<?php
namespace App\Jobs;

use App\Models\VideoPart;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscodeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 任务超时时间 (转码很慢，设置长一点，比如 1 小时)
    public $timeout = 3600;

    public function __construct(
        public VideoPart $videoPart
    ) {}

    public function handle(): void
    {
        $part = $this->videoPart;

        // 1. 检查源文件是否存在
        if (empty($part->video_download_path) || !Storage::disk('public')->exists($part->video_download_path)) {
            Log::warning("转码失败：源文件不存在", ['id' => $part->id]);
            return;
        }

        // 2. 检查是否已经转码过
        if (!empty($part->mobile_download_path) && Storage::disk('public')->exists($part->mobile_download_path)) {
            return;
        }

        $sourcePath = Storage::disk('public')->path($part->video_download_path);
        // 生成兼容版文件名 (例: video.mp4 -> video_mobile.mp4)
        $targetPath = preg_replace('/\.(\w+)$/', '_mobile.mp4', $sourcePath);
        
        // 3. 开始转码 (H.264 + AAC)
        // -c:v libx264: 视频编码器
        // -crf 26: 压缩质量 (23-28 适合移动端，越小越清晰文件越大)
        // -preset veryfast: 转码速度优先
        // -c:a aac: 音频编码器
        $command = sprintf(
            'ffmpeg -y -i %s -c:v libx264 -crf 26 -preset veryfast -c:a aac -b:a 128k -movflags +faststart %s',
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath)
        );

        Log::info("开始转码任务", ['id' => $part->id, 'cmd' => $command]);
        
        exec($command . ' 2>&1', $output, $resultCode);

        if ($resultCode !== 0) {
            Log::error("转码失败", ['output' => $output]);
            return;
        }

        // 4. 更新数据库
        $part->mobile_download_path = get_relative_path($targetPath); // 确保存入相对路径
        $part->save();

        Log::info("转码成功", ['id' => $part->id, 'path' => $part->mobile_download_path]);
    }
}