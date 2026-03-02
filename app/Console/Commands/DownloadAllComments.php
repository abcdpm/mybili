<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\Comment; // 引入 Comment 模型
use Carbon\Carbon;
use App\Jobs\DownloadCommentsJob;
use Illuminate\Console\Command;

class DownloadAllComments extends Command
{
    // video_id: 可选，指定单个视频 ID
    // --force: 强制重新下载（即使已有评论）
    // --limit: 自定义评论下载数量
    // --sleep: 默认 3 秒
    // --status: 查看当前评论下载进度统计 (不执行任务)
    // --incremental: 增量更新模式，在现有基础上追加N条 (如: 20)
    // --max-videos: 用于控制每天轮询的数量
    protected $signature = 'app:download-all-comments
                            {video_id? : 指定视频ID (Video ID)}
                            {--force : 强制重新下载（即使已有评论）}
                            {--incremental= : 增量更新模式，在现有基础上追加N条 (如: 20)}
                            {--limit= : 自定义评论下载数量}
                            {--max-videos=3000 : 每次轮询处理的最大视频数量}
                            {--sleep=3 : 每次请求后的休眠时间(秒)}
                            {--status : 查看当前评论下载进度统计 (不执行任务)}';
    protected $description = 'Dispatch jobs to download comments for all valid videos';

    public function handle(): void
    {
        // [新增] 状态查看模式
        if ($this->option('status')) {
            $this->showStatus();
            return;
        }

        $videoId = $this->argument('video_id');
        $force = $this->option('force');
        $incremental = $this->option('incremental'); // 获取增量值
        $limit = $this->option('limit'); // 获取自定义数量
        $maxVideos = (int) $this->option('max-videos'); // 获取最大视频数量
        $sleep = (int) $this->option('sleep'); //获取 sleep 参数

        $this->info("开始扫描视频并投递评论下载任务...");
        $this->info("设置请求间隔: {$sleep} 秒");

        $query = Video::where('invalid', 0);

        // 指定视频逻辑
        if ($videoId) {
            $query->where('id', $videoId);
            $this->info("模式: 仅处理视频 ID {$videoId}");
        }

        // 模式判断与查询构建
        $isPollingMode = ($incremental !== null && !$videoId && !$force);
        if ($isPollingMode) {
            $this->info("模式: 时间衰减轮询增量更新 (追加 {$incremental} 条)");
            $this->info("策略: 选取最久未更新的 {$maxVideos} 个视频");
            
            // 按 comments_updated_at 升序排。从来没更新过（NULL）的排在最前
            $query->orderBy('comments_updated_at', 'asc')->limit($maxVideos);
            
            $videos = $query->get();
            $total = $videos->count();
        } else {
            if (!$force && !$videoId && $incremental === null) {
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

        // 执行分发逻辑
        if ($isPollingMode) {
            // 轮询模式使用 get() 集合遍历
            foreach ($videos as $video) {
                $localCount = $video->comments()->count();
                $jobLimit = $localCount + (int)$incremental;
                
                dispatch(new DownloadCommentsJob($video, $jobLimit, $sleep));
                $bar->advance();
            }
            // 将这批视频的更新时间刷新为当前时间，防止下次任务重复捞取
            Video::whereIn('id', $videos->pluck('id'))->update([
                'comments_updated_at' => Carbon::now()
            ]);
        } else {
            // 常规模式使用 chunkById 节省内存
            $query->chunkById(100, function ($videos) use ($bar, $limit, $sleep, $incremental) {
                foreach ($videos as $video) {
                    $jobLimit = $limit;
                    // 如果是增量模式，动态计算抓取数量
                    if ($incremental !== null) {
                        $jobLimit = $video->comments()->count() + (int)$incremental;
                    }
                    // 投递任务
                    dispatch(new DownloadCommentsJob($video, $jobLimit, $sleep));
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