<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Jobs\DownloadVideoTagsJob;
use Illuminate\Console\Command;

class DownloadVideoTags extends Command
{
    // 统一命名为 app: 开头，并加入更丰富的参数控制
    protected $signature = 'app:download-tags
                            {video_id? : 指定视频ID (Video ID)}
                            {--force : 强制重新下载（即使已有标签）}
                            {--max-videos=3000 : 每次轮询处理的最大视频数量}
                            {--status : 查看当前标签下载进度统计 (不执行任务)}';
                            
    protected $description = 'Dispatch jobs to download tags for all valid videos into slow queue';

    public function handle(): void
    {
        // 状态查看模式
        if ($this->option('status')) {
            $this->showStatus();
            return;
        }

        $videoId = $this->argument('video_id');
        $force = $this->option('force');
        $maxVideos = (int) $this->option('max-videos');

        $query = Video::query()->where('invalid', 0);

        if ($videoId) {
            $query->where('id', $videoId);
        } else {
            if (!$force) {
                $query->whereNull('tags');
            }
            $query->limit($maxVideos);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info("没有需要处理的视频。");
            return;
        }

        $this->info("开始派发标签下载任务，共计 {$total} 个视频...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // 派发任务 (通过 chunkById 节省内存)
        if ($videoId) {
            $videos = $query->get();
            foreach ($videos as $video) {
                dispatch(new DownloadVideoTagsJob($video));
                $bar->advance();
            }
        } else {
            $query->chunkById(100, function ($videos) use ($bar) {
                foreach ($videos as $video) {
                    dispatch(new DownloadVideoTagsJob($video));
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine();
        $this->info("所有任务已投递至队列，请在 Horizon 中查看进度。");
    }

    // 显示统计信息面板
    protected function showStatus()
    {
        $totalVideos = Video::where('invalid', 0)->count();
        $videosWithTags = Video::where('invalid', 0)->whereNotNull('tags')->count();
        
        $pending = $totalVideos - $videosWithTags;
        $percent = $totalVideos > 0 ? round(($videosWithTags / $totalVideos) * 100, 2) : 0;

        $this->table(
            ['统计项', '数值'],
            [
                ['有效视频总数', $totalVideos],
                ['已下载标签视频数', $videosWithTags],
                ['待处理视频数', $pending],
                ['整体完成进度', "{$percent}%"],
            ]
        );
    }
}