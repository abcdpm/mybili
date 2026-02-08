<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\Comment; // [新增] 引入 Comment 模型
use App\Jobs\DownloadCommentsJob;
use Illuminate\Console\Command;

class DownloadAllComments extends Command
{
    // [修改] 增加 video_id 参数和 force, limit 选项
    // video_id: 可选，指定单个视频 ID
    // --force: 强制重新下载（即使已有评论）
    // --limit: 自定义评论下载数量
    protected $signature = 'app:download-all-comments {video_id?} {--force} {--limit=}';
    
    protected $description = 'Dispatch jobs to download comments for all valid videos';

    public function handle(): void
    {
        $videoId = $this->argument('video_id');
        $force = $this->option('force');
        $limit = $this->option('limit'); // 获取自定义数量

        $this->info("开始扫描视频并投递评论下载任务...");

        $query = Video::where('invalid', 0);

        // [新增] 1. 指定视频逻辑
        if ($videoId) {
            $query->where('id', $videoId);
            $this->info("模式: 仅处理视频 ID {$videoId}");
        }

        // [新增] 2. 跳过已备份逻辑 (如果没有 --force 且没有指定单个视频)
        // 如果指定了单个视频，通常默认是想立即执行，也可以配合 force 使用，这里逻辑根据 needs 调整
        // 这里假设全局扫描时才默认跳过
        if (!$force && !$videoId) {
            // 假设 Comment 表有 video_id 字段，且 Video 模型有关联方法 comments()
            // 如果没有关联方法，可以使用 whereDoesntHave 的替代写法
            $query->whereDoesntHave('comments'); 
            $this->info("模式: 跳过已有评论的视频");
        } elseif ($force) {
             $this->info("模式: 强制覆盖/追加");
        }

        $total = $query->count();
        if ($total == 0) {
            $this->info("没有发现需要处理的视频。");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // 将 limit 传给 Job
        $query->chunkById(100, function ($videos) use ($bar, $limit) {
            foreach ($videos as $video) {
                // [修改] 传递 limit 参数到 Job
                dispatch(new DownloadCommentsJob($video, $limit)); 
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("所有任务已投递至队列，请在 Horizon 中查看进度。");
    }
}