<?php

namespace App\Console\Commands;

use App\Models\VideoPart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncMobileVideos extends Command
{
    protected $signature = 'app:sync-mobile-videos';
    protected $description = '扫描磁盘上已存在的手机版视频并同步到数据库';

    public function handle()
    {
        $this->info("开始检查已转码文件...");

        // 1. 查找所有“已下载”但“未记录手机版”的分P
        $parts = VideoPart::whereNotNull('video_download_path')
            ->whereNull('mobile_download_path')
            ->cursor(); // 使用 cursor 减少内存占用

        $count = 0;
        $bar = $this->output->createProgressBar();

        foreach ($parts as $part) {
            $bar->advance();

            // 获取源文件路径 (容器内的相对路径)
            $sourcePath = $part->video_download_path;
            
            // 推算预计的手机版文件名
            // 规则：文件名_mobile.mp4
            $mobilePath = preg_replace('/\.(\w+)$/', '_mobile.mp4', $sourcePath);

            // 检查文件是否已经存在于磁盘上
            if (Storage::disk('public')->exists($mobilePath)) {
                // 如果文件存在，更新数据库
                $part->mobile_download_path = $mobilePath;
                $part->saveQuietly(); // 不触发事件，静默保存
                
                $count++;
                // $this->newLine();
                // $this->info("发现并同步: {$mobilePath}");
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("同步完成！共更新 {$count} 个视频记录。");
    }
}