<?php
namespace App\Services;

use App\Models\Cover;
use App\Models\Coverables;
use Illuminate\Database\Eloquent\Model;
use App\Jobs\DownloadCoverImageJob;
use Illuminate\Support\Facades\Log;

class CoverService extends DownloadImageService
{
    public function downloadCoverImageJob(string $url, string $type, Model $model): void
    {
        dispatch(new DownloadCoverImageJob($url, $type, $model));
    }

    /**
     * 下载封面并创建关联关系
     * * @param string $url 图片URL
     * @param string $type 封面类型 (video, avatar, favorite)
     * @param Model $model 关联的模型实例
     * @return Cover
     * @throws \Exception
     */
    public function downloadCover(string $url, string $type, Model $model): Cover
    {
        // 1. 获取基础文件名（通常是 .jpg 或 .png）
        $originalFilename = $this->convertToFilename($url);
        // 构造预期的 WebP 文件名
        $webpFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '.webp';
        
        // 2. 优先检查数据库中是否已存在该封面的 WebP 版本
        $cover = Cover::where('filename', $webpFilename)
                      ->orWhere('filename', $originalFilename) // 兼容旧数据
                      ->first();
        
        // 3. 如果封面不存在，执行下载与转换流程
        if (!$cover) {
            $localPath = $this->getImageLocalPath($url);
            
            // 3.1 下载原图
            if (!is_file($localPath)) {
                $this->downloadImage($url, $localPath);
            }

            // 3.2 【新增】转换为 WebP 格式
            // convertToWebp 方法会返回转换后的新路径（.webp），如果转换失败则返回原路径
            $finalPath = $this->convertToWebp($localPath);
            
            // 3.3 准备入库数据
            $finalFilename = basename($finalPath); // 可能是 .webp 也可能是 .jpg（若转换失败）
            $imageInfo = $this->getImageInfo($finalPath);
            
            // 3.4 创建封面记录
            // 关键：防止并发插入导致的 Unique constraint failed
            try {
                $cover = Cover::firstOrCreate(
                    ['filename' => $finalFilename], // 以文件名为唯一键查找
                    [
                        'url'       => $url,
                        'type'      => $type,
                        'path'      => get_relative_path($finalPath),
                        'mime_type' => $imageInfo['mime_type'],
                        'size'      => $imageInfo['size'],
                        'width'     => $imageInfo['width'],
                        'height'    => $imageInfo['height'],
                    ]
                );
            } catch (\Illuminate\Database\QueryException $e) {
                // 如果并发插入失败（错误码 23000），则重新查询一次
                if ($e->getCode() === '23000') {
                    $cover = Cover::where('filename', $finalFilename)->firstOrFail();
                } else {
                    throw $e;
                }
            }
        }
        
        // 4. 创建或更新关联关系
        Coverables::updateOrCreate(
            [
                'coverable_id'    => $model->id,
                'coverable_type'  => get_class($model),
            ],
            [
                'cover_id'        => $cover->id,
            ]
        );
        
        return $cover;
    }

    /**
     * 将图片转换为 WebP 格式
     * * @param string $sourcePath 原图路径
     * @param int $quality 压缩质量 (0-100)
     * @return string 返回最终的文件路径 (成功则为 .webp，失败则为原路径)
     */
    protected function convertToWebp(string $sourcePath, int $quality = 80): string
    {
        // 检查环境是否支持 GD 库和 WebP
        if (!function_exists('imagewebp')) {
            Log::warning("GD Library missing or WebP not supported. Skipping conversion for: {$sourcePath}");
            return $sourcePath;
        }

        try {
            $info = @getimagesize($sourcePath);
            if (!$info) return $sourcePath;

            $mime = $info['mime'];
            $image = null;

            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $image = @imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($sourcePath);
                    if ($image) {
                        // 保持 PNG 透明度
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                    }
                    break;
                // 可以根据需要添加其他格式支持
                default:
                    return $sourcePath; // 不支持的格式直接返回原路径
            }

            if ($image) {
                // 构造新的 WebP 文件路径
                $dir = dirname($sourcePath);
                $name = pathinfo($sourcePath, PATHINFO_FILENAME);
                $webpPath = $dir . DIRECTORY_SEPARATOR . $name . '.webp';

                // 执行转换
                if (imagewebp($image, $webpPath, $quality)) {
                    imagedestroy($image);
                    
                    // 转换成功后：
                    // 1. 确保新文件存在且不为空
                    // 2. 删除原图文件 (和可能存在的 .hash 文件)
                    if (file_exists($webpPath) && filesize($webpPath) > 0) {
                        if ($sourcePath !== $webpPath && file_exists($sourcePath)) {
                            @unlink($sourcePath);
                            @unlink($sourcePath . '.hash'); // 如果 DownloadImageService 生成了 hash，也一并删除
                        }
                        return $webpPath;
                    }
                }
                // 如果保存失败，释放内存
                imagedestroy($image);
            }
        } catch (\Exception $e) {
            Log::error("WebP conversion failed for {$sourcePath}: " . $e->getMessage());
        }

        // 转换过程出现任何问题，都回退到使用原图
        return $sourcePath;
    }
    
    /**
     * 获取图片详细信息
     */
    protected function getImageInfo(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new \Exception("Image file not found: {$filePath}");
        }
        
        $imageSize = @getimagesize($filePath);
        if ($imageSize === false) {
            throw new \Exception("Failed to get image size for: {$filePath}");
        }
        
        $fileSize = filesize($filePath);
        
        return [
            'width'     => $imageSize[0] ?? 0,
            'height'    => $imageSize[1] ?? 0,
            'mime_type' => $imageSize['mime'] ?? 'image/jpeg',
            'size'      => $fileSize,
        ];
    }

    public function isDownloaded(string $url): bool
    {
        $filename = $this->convertToFilename($url);
        $webpFilename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
        
        // 检查数据库中是否存在 原文件名 或 WebP文件名
        return Cover::where('filename', $filename)
                    ->orWhere('filename', $webpFilename)
                    ->exists();
    }

    public function isCoverable(string $url, Model $model): bool
    {
        // 这里沿用原逻辑检查关联是否存在
        // 实际上这行逻辑有点问题（应该查 cover_id 关联），但为了兼容性暂保持原状，仅修改文件匹配逻辑
        $filename = $this->convertToFilename($url);
        // 如果上面 isDownloaded 返回 true，说明封面已存在（可能是webp）
        // 这里只是判断是否已建立关联
        return Coverables::where('coverable_id', $model->id)
                         ->where('coverable_type', get_class($model))
                         ->exists();
    }
}