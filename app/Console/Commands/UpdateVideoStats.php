<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Jobs\PullVideoInfoJob;
use Illuminate\Console\Command;

class UpdateVideoStats extends Command
{
    protected $signature = 'app:update-video-stats 
                            {video_id? : 指定视频ID (Video ID)} 
                            {--force : 强制重新拉取所有有效视频的信息}
                            {--status : 查看当前视频播放量获取进度统计 (不执行任务)}
                            {--auto-update : 自动增量更新模式 (每天抽取最久未更新的视频)}
                            {--percent=3 : 抽取有效视频总数的百分比 (默认 3%)}
                            {--sleep=5 : 每个任务派发到队列的执行间隔延迟(秒)，防止触发风控}';
                            
    protected $description = '安全的队列派发：定期更新或修复存量视频的播放量和时长数据';

    public function handle(): void
    {
        // 状态查看模式
        if ($this->option('status')) {
            $this->showStatus();
            return;
        }

        $videoId    = $this->argument('video_id');
        $force      = $this->option('force');
        $autoUpdate = $this->option('auto-update');
        $percent    = (float) $this->option('percent');
        $sleep      = (int) $this->option('sleep');

        $this->info("开始扫描数据库中的存量视频...");

        // 基础查询：仅处理未失效的有效视频
        $query = Video::where('invalid', 0);

        // 模式 1: 单个视频调试
        if ($videoId) {
            $videos = $query->where('id', $videoId)->get();
            $this->info("模式: 仅处理视频 ID {$videoId}");
            $this->dispatchJobs($videos, $sleep);
            return;
        }

        // 模式 2: 自动轮询增量更新 (比如每天 3%)
        if ($autoUpdate) {
            $total = $query->count();
            $limit = max(1, (int)ceil($total * ($percent / 100)));
            $this->info("模式: 自动增量轮询. 将抽取最久未更新的 {$percent}% 视频 (共 {$limit} 个)");

            // 利用 updated_at 升序，完美实现“循环更新最久未获取的视频”
            $videos = Video::where('invalid', 0)
                ->orderBy('updated_at', 'asc')
                ->limit($limit)
                ->get();

            $this->dispatchJobs($videos, $sleep);
            return;
        }

        // 模式 3: 默认修复确实没数据的
        if (!$force) {
            $query->where(function($q) {
                $q->where('view', 0)->orWhere('duration', 0);
            });
            $this->info("模式: 增量修复 (仅处理播放量或时长为 0 的视频)。");
            $this->comment("提示: 日常按比例平滑更新，请加上 --auto-update 参数");

            $videos = $query->get();
            $this->dispatchJobs($videos, $sleep);
            return;
        }

        // 模式 4: 强制全量更新 (虽然支持，但不推荐日常使用)
        if ($force) {
            $this->info("模式: 强制更新 (全量刷新所有有效视频的最新播放量)");
            $this->warn("警告: 全量任务已自动应用 {$sleep} 秒/个 的间隔保护。");

            $count = $query->count();
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            $delayCounter = 0;
            $query->chunkById(500, function ($chunkVideos) use ($bar, &$delayCounter, $sleep) {
                foreach ($chunkVideos as $video) {
                    // 核心防封逻辑：推入队列时加上 delay()
                    $delay = now()->addSeconds($delayCounter * $sleep);
                    dispatch(new PullVideoInfoJob($video->bvid))->delay($delay);
                    $delayCounter++;
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine();
            $this->info("全量任务已按时间间隔打散推送到队列！");
        }
    }

    // 统一下发任务，利用 Queue 的 delay 进行任务时间线打散
    private function dispatchJobs($videos, int $sleep): void
    {
        $count = $videos->count();
        if ($count === 0) {
            $this->info("没有发现符合条件的视频，无需更新。");
            return;
        }
        $this->info("待处理视频总数: {$count}");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($videos as $index => $video) {
            // 💡 绝妙之处：无论队列有多少个并发进程，我们在投递时就设定好它的“起跑时间”
            // 比如第1个立即执行，第2个5秒后，第3个10秒后... 强制把 API 请求速率降下来
            $delay = now()->addSeconds($index * $sleep);
            dispatch(new PullVideoInfoJob($video->bvid))->delay($delay);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("任务已按 {$sleep} 秒/个的间隔打散并推送到队列！将在后台无感平滑执行。");
    }

    // 显示统计信息的方法
    protected function showStatus(): void
    {
        $total = Video::where('invalid', 0)->count();
        
        // 统计已经获取到播放量（view > 0）的视频
        $done = Video::where('invalid', 0)
                     ->where('view', '>', 0)
                     ->count();
        
        $pending = $total - $done;
        $percent = $total > 0 ? round(($done / $total) * 100, 2) : 0;

        $this->table(
            ['统计项', '数值'],
            [
                ['有效视频总数', $total],
                ['已获取播放量/时长', $done],
                ['缺失数据 (待修复)', $pending],
                ['数据完整度', "{$percent}%"],
            ]
        );
        
        $this->info("注意：后台队列处理需要时间，'已获取' 的数量会随着 Horizon 处理逐渐上升。");
    }
}