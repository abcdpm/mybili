<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\Comment; // 引入 Comment 模型
use Carbon\Carbon;
use App\Jobs\DownloadCommentsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Enums\SettingKey;
use App\Services\SettingsService;

class DownloadAllComments extends Command
{
    // video_id: 可选，指定单个视频 ID
    // --force: 强制重新下载（即使已有评论）
    // --limit: 自定义评论下载数量
    // --sleep: 默认 3 秒
    // --status: 查看当前评论下载进度统计 (不执行任务)
    // --incremental: 增量更新模式，在现有基础上追加N条 (如: 20)
    // --max-videos: 用于控制每天轮询的数量
    // --auto-update : 开启智能模式，进行自动轮询，按时间衰减自适应刷新已有视频的最新评论
    // --percent=3 : 智能模式下，按有效视频总数的百分比执行 (如: 3 表示 3%)
    protected $signature = 'app:download-all-comments
                            {video_id? : 指定视频ID (Video ID)}
                            {--force : 强制重新下载（即使已有评论）}
                            {--incremental= : 增量更新模式，在现有基础上追加N条 (如: 20)}
                            {--limit= : 自定义评论下载数量}
                            {--max-videos= : 每次轮询处理的最大视频数量}
                            {--sleep=3 : 每次请求后的休眠时间(秒)}
                            {--interval= : 轮询模式下相邻任务的投递间隔秒数（默认自动：当日任务摊到约20小时）}
                            {--auto-update : 开启智能模式，进行自动轮询，按时间衰减自适应刷新已有视频的最新评论}
                            {--percent=3 : 智能模式下，按有效视频总数的百分比执行 (如: 3 表示 3%)}
                            {--status : 查看当前评论下载进度统计 (不执行任务)}';
    protected $description = 'Dispatch jobs to download comments for all valid videos';

    public function handle(): void
    {
        // 检查系统设置：是否允许评论下载
        $downloadCommentsEnabled = app(SettingsService::class)->get(SettingKey::DOWNLOAD_COMMENTS_ENABLED) ?? 'on';
        if ($downloadCommentsEnabled !== 'on') {
            $this->info("[视频评论] 系统设置已关闭评论下载功能，跳过执行");
            return;
        }

        // 状态查看模式
        if ($this->option('status')) {
            $this->showStatus();
            return;
        }

        $videoId = $this->argument('video_id');
        $force = $this->option('force');
        $incremental = $this->option('incremental'); // 获取增量值
        $limit = $this->option('limit'); // 获取自定义数量
        $maxVideosOpt = (int) $this->option('max-videos'); // 获取最大视频数量
        $sleep = (int) $this->option('sleep'); //获取 sleep 参数
        $isAutoUpdate = $this->option('auto-update');
        $percent = (float) $this->option('percent');

        $this->info("开始扫描视频并投递评论下载任务...");
        $this->info("设置请求间隔: {$sleep} 秒");

        $query = Video::where('invalid', 0);

        // 指定视频逻辑
        if ($videoId) {
            $query->where('id', $videoId);
            $this->info("模式: 仅处理视频 ID {$videoId}");
        }

        // 模式判断：是否是轮询模式 (增量 或 智能)
        $isPollingMode = ($incremental !== null || $isAutoUpdate) && !$videoId && !$force;
        
        if ($isPollingMode) {
            if ($isAutoUpdate) {
                // -------------------------------------------------------------
                // [智能模式] 基于百分比计算，自适应抓取数量
                // -------------------------------------------------------------
                $totalValidVideos = Video::where('invalid', 0)->count();
                $calculatedMax = max(50, (int)($totalValidVideos * ($percent / 100)));
                
                $maxVideos = $maxVideosOpt ?: $calculatedMax;

                $this->info("模式: 智能轮询更新 (动态占比 {$percent}%)");
                $this->info("策略: 选取最久未更新的 {$maxVideos} 个视频，自适应刷新最新评论");
                if($incremental !== null) {
                    $this->info("增量: 在智能模式基础上，追加 {$incremental} 条评论");
                }
            } else {
                // -------------------------------------------------------------
                // [传统模式] 固定数量增量追加 (兼容旧用户)
                // -------------------------------------------------------------
                $maxVideos = $maxVideosOpt ?: 3000;
                $this->info("模式: 传统衰减轮询 (追加 {$incremental} 条)");
                $this->info("策略: 选取最久未更新的 {$maxVideos} 个视频");
            }
            
            // 按 comments_updated_at 升序排
            $query->orderBy('comments_updated_at', 'asc')->limit($maxVideos);
            
            $videos = $query->get();
            $total = $videos->count();
        } else {
            if (!$force && !$videoId && !$isAutoUpdate && $incremental === null) {
                $query->whereDoesntHave('comments'); 
                $this->info("模式: 跳过已有评论的视频");
            } elseif ($force) {
                $this->info("模式: 强制覆盖/追加");
            }
            $total = $query->count();
        }

        if ($total == 0) {
            $this->info("没有发现需要处理的视频。");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        if ($isPollingMode) {
            // 【第12项】调度重构：
            // 1) 任务错峰投递——按 --interval 把当日任务均匀摊开（默认约 20 小时），
            //    由延迟队列承担等待；替代旧版"凌晨一次性投递 + 每任务 sleep 阻塞
            //    worker"，彻底解决"每天跑不完、任务大量堆积"的问题；
            // 2) 评论数一次聚合预取，消除逐条 count 的 N+1。
            $totalJobs = count($videos);
            $interval  = (int) ($this->option('interval') ?: max(1, intdiv(72000, max(1, $totalJobs))));

            $localCounts = DB::table('comments')
                ->selectRaw('video_id, COUNT(*) AS c')
                ->whereIn('video_id', $videos->pluck('id'))
                ->groupBy('video_id')
                ->pluck('c', 'video_id');

            $index = 0;
            foreach ($videos as $video) {
                // 已有评论数 + 增量追加（$incremental 为空时交由 Action 自适应模型）
                $localCount = (int) ($localCounts[$video->id] ?? 0);
                $jobLimit   = $localCount + (int) $incremental;

                dispatch(
                    (new DownloadCommentsJob($video, $jobLimit, 0, 'comments-batch'))
                        ->delay(now()->addSeconds($index * $interval))
                );
                $index++;
                $bar->advance();
            }
            $this->newLine();
            $this->info(sprintf(
                '已错峰投递 %d 个任务：间隔 %d 秒（约 %d 小时内平稳消化），队列 comments-batch',
                $totalJobs, $interval, (int) ceil($totalJobs * $interval / 3600)
            ));

            // 刷新更新时间，防止重复捞取
            Video::whereIn('id', $videos->pluck('id'))->update([
                'comments_updated_at' => Carbon::now()
            ]);        } else {
            // 常规模式
            $query->chunkById(100, function ($videos) use ($bar, $limit, $sleep, $incremental) {
                foreach ($videos as $video) {
                    $jobLimit = $limit;
                    if ($incremental !== null) {
                        $jobLimit = $video->comments()->count() + (int)$incremental;
                    }
                    dispatch(new DownloadCommentsJob($video, $jobLimit, $sleep, 'comments-batch'));
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine();
        $this->info("所有任务已投递至队列，请在 Horizon 中查看进度。");
    }

    // 显示统计信息的方法
    protected function showStatus()
    {
        // 总视频数 (仅统计有效的)
        $totalVideos = Video::where('invalid', 0)->count();
        
        // 已有评论的视频数
        $videosWithComments = Video::where('invalid', 0)->whereHas('comments')->count();
        
        // 总评论数
        $totalComments = Comment::count();

        $pending = $totalVideos - $videosWithComments;
        $percent = $totalVideos > 0 ? round(($videosWithComments / $totalVideos) * 100, 2) : 0;

        $this->table(
            ['统计项', '数值'],
            [
                ['有效视频总数', $totalVideos],
                ['已下载评论视频数', $videosWithComments],
                ['待处理视频数', $pending],
                ['评论库总条数', $totalComments],
                ['覆盖进度', "{$percent}%"],
            ]
        );
        
        $this->info("注意：此统计基于数据库当前状态。");
    }
}