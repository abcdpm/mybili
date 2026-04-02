<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Cover;
use App\Models\Comment;
use App\Jobs\OptimizeImagesJob; // 【修改】引入统一的 Job

class OptimizeImages extends Command
{
    protected $signature = 'app:optimize-images';
                            
    protected $description = '将全站图片优化和数据库升级任务推送到 slow 后台队列执行';

    public function handle(): void
    {
        $this->info("==== 1. 开始将评论数据升级任务推送到 slow 队列 ====");
        $commentsCount = Comment::count();
        if ($commentsCount > 0) {
            $bar = $this->output->createProgressBar($commentsCount);
            $bar->start();

            // 每次取 1000 个 ID 发到队列
            Comment::select('id')->chunkById(1000, function ($comments) use ($bar) {
                foreach ($comments as $comment) {
                    OptimizeImagesJob::dispatch('comment', $comment->id);
                    $bar->advance();
                }
            });
            $bar->finish();
        } else {
            $this->info("没有评论数据需要处理。");
        }
        $this->newLine(2);

        $this->info("==== 2. 开始将封面优化任务推送到 slow 队列 ====");
        $covers = Cover::where('filename', 'not like', '%.webp')->select('id')->get();
        if ($covers->count() > 0) {
            $bar2 = $this->output->createProgressBar($covers->count());
            $bar2->start();

            foreach ($covers as $cover) {
                OptimizeImagesJob::dispatch('cover', $cover->id);
                $bar2->advance();
            }
            $bar2->finish();
        } else {
            $this->info("没有封面图片需要处理。");
        }
        $this->newLine(2);

        $this->info("🚀 分发完毕！所有图片清洗任务已成功加入 slow 队列！");
    }
}