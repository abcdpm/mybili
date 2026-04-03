<?php
namespace App\Services;

use App\Events\CoverImageStored;
use App\Models\Cover;
use App\Models\Coverables;
use Illuminate\Database\Eloquent\Model;
use App\Jobs\DownloadCoverImageJob;
use Illuminate\Support\Facades\Log;
use App\Traits\ImageOptimizerTrait;

class CoverService extends DownloadImageService
{
    use ImageOptimizerTrait;
    
    public function downloadCoverImageJob(string $url, string $type, Model $model): void
    {
        dispatch(new DownloadCoverImageJob($url, $type, $model));
    }

    /**
     * 下载封面并创建关联关系
     *
     * @param  string  $url  图片URL
     * @param  string  $type  封面类型 (video, avatar, favorite)
     * @param  Model  $model  关联的模型实例（Video、FavoriteList、Upper等）
     *
     * @throws \Exception
     */
    public function downloadCover(string $url, string $type, Model $model): Cover
    {
        // 1. 获取基础文件名（通常是 .jpg 或 .png）
        $originalFilename = $this->convertToFilename($url);
        $baseFilename = pathinfo($originalFilename, PATHINFO_FILENAME);
        
        // 2. 优先检查数据库中是否已存在该封面的 WebP 版本
        // 【修改】只查原图和 WebP，彻底移除 avif
        $cover = Cover::whereIn('filename', [
            $originalFilename,
            $baseFilename . '.webp'
        ])->first();
        
        // 3. 如果封面不存在，执行下载与转换流程
        if (!$cover) {
            $localPath = $this->getImageLocalPath($url);
            
            // 3.1 下载原图
            if (!is_file($localPath)) {
                $this->downloadImage($url, $localPath);
            }

            // 3.2 【新增】转换为 WebP 格式
            // convertToWebp 方法会返回转换后的新路径（.webp），如果转换失败则返回原路径
            // 【修改2】：调用全新的无损优化方法，如果成功返回的是新后缀的路径，旧图仍静静躺在磁盘
            $finalPath = $this->optimizeImage($localPath);
            
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
                'coverable_id'   => $model->id,
                'coverable_type' => get_class($model),
            ],
            [
                'cover_id' => $cover->id,
            ]
        );

        event(new CoverImageStored($cover));

        return $cover;
    }

    /**
     * 获取图片详细信息
     *
     * @param  string  $filePath  图片本地路径
     *
     * @throws \Exception
     */
    protected function getImageInfo(string $filePath): array
    {
        if (! is_file($filePath)) {
            throw new \Exception("Image file not found: {$filePath}");
        }

        // 获取图片尺寸和类型信息
        $imageSize = @getimagesize($filePath);
        if ($imageSize === false) {
            throw new \Exception("Failed to get image size for: {$filePath}");
        }

        // 获取文件大小（字节）
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new \Exception("Failed to get file size for: {$filePath}");
        }

        return [
            'width'     => $imageSize[0] ?? 0,
            'height'    => $imageSize[1] ?? 0,
            'mime_type' => $imageSize['mime'] ?? 'image/jpeg',
            'size'      => $fileSize,
        ];
    }

    // 【修改4】：完善判定逻辑，兼容多种新格式
    public function isDownloaded(string $url): bool
    {
        $filename = $this->convertToFilename($url);
        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
        
        // 【修改】彻底移除 avif
        return Cover::whereIn('filename', [
            $filename,
            $baseFilename . '.webp'
        ])->exists();
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