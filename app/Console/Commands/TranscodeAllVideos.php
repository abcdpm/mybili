<?php
namespace App\Console\Commands;

use App\Jobs\TranscodeVideoJob;
use App\Models\VideoPart;
use Illuminate\Console\Command;

class TranscodeAllVideos extends Command
{
    protected $signature = 'app:transcode-all {--force : 强制重新转码}';
    protected $description = '将所有已下载视频转码为移动端兼容格式';

    public function handle()
    {
        $this->info("开始扫描存量视频...");

        $query = VideoPart::whereNotNull('video_download_path');

        if (!$this->option('force')) {
            // 默认只处理还没转码的
            $query->whereNull('mobile_download_path');
        }

        $query->chunk(100, function ($parts) {
            foreach ($parts as $part) {
                $this->info("加入转码队列: {$part->title} (CID: {$part->cid})");
                // 确保 TranscodeVideoJob 在发布时被推送到 slow 队列
                dispatch(new TranscodeVideoJob($part))->onQueue('slow');
            }
        });

        $this->info("所有任务已推送到队列后台处理。");
    }
}