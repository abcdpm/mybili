<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Jobs\DownloadCommentsJob;
use Illuminate\Console\Command;

class DownloadAllComments extends Command
{
    protected $signature = 'app:download-all-comments';
    protected $description = 'Dispatch jobs to download comments for all valid videos';

    public function handle(): void
    {
        $this->info("开始全量扫描视频并投递评论下载任务...");

        $query = Video::where('invalid', 0);
        $total = $query->count();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($videos) use ($bar) {
            foreach ($videos as $video) {
                dispatch(new DownloadCommentsJob($video));
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("所有任务已投递至队列，请在 Horizon 中查看进度。");
    }
}