<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait ImageOptimizerTrait
{
    /**
     * 将图片统一转换为更节省带宽的 WebP 格式
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

        // 1. 本身是动图或高效格式，直接跳过
        if (in_array($mime, ['image/gif', 'image/webp'])) {
            return $sourcePath;
        }

        // 2. 拦截 B站 常用 APNG 动态表情包
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

                // 统一转换为 WebP 格式
                if (function_exists('imagewebp')) {
                    $webpPath = $dir . DIRECTORY_SEPARATOR . $name . '.webp';
                    if (@imagewebp($image, $webpPath, $quality)) {
                        // 【极度关键】清除文件状态缓存，彻底杜绝 filesize() 误报 0 字节
                        clearstatcache(true, $webpPath);
                        if (filesize($webpPath) > 0) {
                            imagedestroy($image);
                            return $webpPath; // 转换成功
                        }
                        // 转码器输出损坏文件则丢弃，退回原图
                        @unlink($webpPath);
                    }
                }
                imagedestroy($image);
            }
        } catch (\Exception $e) {
            Log::error("[图片优化] 图片优化失败: {$sourcePath}: " . $e->getMessage());
        }

        return $sourcePath; // 发生异常回退到原图
    }
}