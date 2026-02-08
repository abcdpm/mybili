<?php
namespace App\Console\Commands;

use App\Jobs\TranscodeVideoJob;
use App\Models\VideoPart;
use Illuminate\Console\Command;

class TranscodeAllVideos extends Command
{
    // [修改] 增加 video_id 参数
    protected $signature = 'app:transcode-all {video_id? : 指定视频ID (Video ID)} {--force : 强制重新转码}';
    protected $description = '将所有已下载视频转码为移动端兼容格式';

    public function handle()
    {
        $videoId = $this->argument('video_id');
        $force = $this->option('force');

        $this->info("开始扫描存量视频...");

        // 基础查询：必须是已下载的视频分P
        $query = VideoPart::whereNotNull('video_download_path');

        // [新增] 指定视频逻辑
        if ($videoId) {
            // 假设 VideoPart 表中有 video_id 字段关联到 Video 表
            $query->where('video_id', $videoId);
            $this->info("模式: 仅处理视频 ID {$videoId} 下的分P");
        }

        // [现有逻辑保持] 默认跳过已转码 (mobile_download_path 不为空)
        if (!$force) {
            $query->whereNull('mobile_download_path');
            if (!$videoId) {
                $this->info("模式: 跳过已转码视频");
            }
        } else {
            $this->info("模式: 强制重新转码");
        }

        $count = $query->count();
        $this->info("待处理分P数量: {$count}");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunk(100, function ($parts) use ($bar) {
            foreach ($parts as $part) {
                // $this->info("加入转码队列: {$part->title} (CID: {$part->cid})"); // 放在进度条里最好不要输出太多 info
                dispatch(new TranscodeVideoJob($part))->onQueue('slow');
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("所有任务已推送到队列后台处理。");
    }
}