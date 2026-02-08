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
        public VideoPart $videoPart,
        public string $mode = 'cpu' // cpu, qsv, nvenc
    ) {}

    public function handle(): void
    {
        $part = $this->videoPart;

        // 定义一个基于视频分P CID 的唯一锁
        $lockKey = 'locking:transcode:' . $part->cid;
        // 尝试加锁，有效期 1 小时
        $lock = redis()->set($lockKey, 1, ['NX', 'EX' => 3600]);
        if (!$lock) {
        Log::info("该视频正在被另一个进程转码，跳过本次任务", ['cid' => $part->cid]);
            return;
        }

        try {
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
            // 构建命令
            $command = $this->buildCommand($sourcePath, $targetPath);

            Log::info("开始转码任务", ['id' => $part->id, 'mode' => $this->mode, 'cmd' => $command]);
            
            exec($command . ' 2>&1', $output, $resultCode);

            if ($resultCode !== 0) {
                Log::error("转码失败", ['output' => $output]);
                return;
            }

            // 4. 更新数据库
            $part->mobile_download_path = get_relative_path($targetPath); // 确保存入相对路径
            $part->save();

            Log::info("转码成功", ['id' => $part->id, 'path' => $part->mobile_download_path]);
        } finally {
            // 任务结束（无论成功失败）务必释放锁
            redis()->del($lockKey);
        }
    }

    // 独立的 ffmpeg 转码命令构建方法，逻辑更清晰
    private function buildCommand(string $source, string $target): string
    {
        $commonAudio = '-c:a aac -b:a 128k'; // 音频参数通用
        $commonFlags = '-movflags +faststart'; // Web播放优化通用

        switch ($this->mode) {
            case 'nvenc':
                // NVIDIA 硬件加速
                // -hwaccel cuda: 使用 CUDA 进行硬件解码
                // -c:v h264_nvenc: 使用 NVIDIA H.264 编码器
                // -cq 26: 恒定质量模式 (类似 CRF)，数值越大质量越低
                // -preset p4: 性能/质量平衡预设 (p1最快 - p7质量最好)
                return sprintf(
                    'ffmpeg -y -hwaccel cuda -i %s -c:v h264_nvenc -preset p4 -cq 26 %s %s %s',
                    escapeshellarg($source),
                    $commonAudio,
                    $commonFlags,
                    escapeshellarg($target)
                );

            case 'qsv':
                // Intel QSV 硬件加速
                // -hwaccel qsv: 启用硬件加速解码（进一步减轻 CPU 负担）
                // -c:v h264_qsv: 使用 Intel 硬件 H.264 编码器
                // -global_quality 25: QSV 中的质量控制，类似 CRF（数值越小越清晰，建议 20-28）
                // -preset veryfast: QSV 同样支持速度预设 转码速度优先
                // 注意：确保 Docker 容器已映射 /dev/dri 设备
                return sprintf(
                    'ffmpeg -y -hwaccel qsv -i %s -c:v h264_qsv -global_quality 25 -preset veryfast %s %s %s',
                    escapeshellarg($source),
                    $commonAudio,
                    $commonFlags,
                    escapeshellarg($target)
                );

            case 'cpu':
            default:
                // 纯 CPU 软解
                // -c:v libx264: 视频编码器
                // -crf 26: 压缩质量 (23-28 适合移动端，越小越清晰文件越大)
                // -preset veryfast: 转码速度优先
                return sprintf(
                    'ffmpeg -y -i %s -c:v libx264 -crf 26 -preset veryfast %s %s %s',
                    escapeshellarg($source),
                    $commonAudio,
                    $commonFlags,
                    escapeshellarg($target)
                );
        }
    }
}