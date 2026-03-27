<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait ImageOptimizerTrait
{
    /**
     * 将图片转换为更节省带宽的格式（AVIF 或 WebP）
     * 核心逻辑：保留原图，生成新图，遇到动图自动跳过
     *
     * @param string $sourcePath 原图绝对路径
     * @param int $quality 压缩质量 (0-100)
     * @return string 返回最终用来展示的文件绝对路径（成功则为 .avif/.webp，失败或跳过则为原路径）
     */
    protected function optimizeImage(string $sourcePath, int $quality = 80): string
    {
        if (!file_exists($sourcePath)) return $sourcePath;

        $info = @getimagesize($sourcePath);
        if (!$info) return $sourcePath;

        $mime = $info['mime'];

        // 1. 如果本身就是高效格式，或者标准 GIF 动图，直接跳过保留原状
        if (in_array($mime, ['image/gif', 'image/webp', 'image/avif'])) {
            return $sourcePath;
        }

        // 2. 拦截 APNG (Animated PNG)：B站常用 APNG 做动态表情包，转 WebP 会变静图
        if ($mime === 'image/png') {
            $header = file_get_contents($sourcePath, false, null, 0, 1024);
            if (strpos($header, 'acTL') !== false) {
                return $sourcePath; // 发现 APNG 动画特征，原样返回
            }
        }

        $image = null;
        try {
            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $image = @imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($sourcePath);
                    if ($image) {
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                    }
                    break;
            }

            if ($image) {
                $dir = dirname($sourcePath);
                $name = pathinfo($sourcePath, PATHINFO_FILENAME);

                // 优先尝试转为 AVIF
                if (function_exists('imageavif')) {
                    $avifPath = $dir . DIRECTORY_SEPARATOR . $name . '.avif';
                    // 尝试生成，并拦截静默失败
                    if (@imageavif($image, $avifPath, $quality)) {
                        // 【核心修复】：检查是否生成了 0 字节的空文件
                        if (filesize($avifPath) > 0) {
                            imagedestroy($image);
                            return $avifPath; 
                        }
                        // 如果是 0 字节，说明底层编码器罢工，删掉废文件，继续往下尝试降级
                        @unlink($avifPath);
                    }
                }

                // 降级使用 WebP 格式
                if (function_exists('imagewebp')) {
                    $webpPath = $dir . DIRECTORY_SEPARATOR . $name . '.webp';
                    if (@imagewebp($image, $webpPath, $quality)) {
                        // 【核心修复】：同样检查 WebP 是否正常
                        if (filesize($webpPath) > 0) {
                            imagedestroy($image);
                            return $webpPath; 
                        }
                        @unlink($webpPath);
                    }
                }

                imagedestroy($image);
            }
        } catch (\Exception $e) {
            Log::error("Image optimization failed for {$sourcePath}: " . $e->getMessage());
        }

        // 发生异常或不支持，安全回退到原图
        return $sourcePath;
    }
}