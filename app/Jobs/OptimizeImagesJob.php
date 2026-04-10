<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Comment;
use App\Models\Cover;
use App\Services\CommentImageService;
use App\Traits\ImageOptimizerTrait;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class OptimizeImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ImageOptimizerTrait;

    public $timeout = 120;
    public $tries = 3;

    protected $type;
    protected $modelId;

    /**
     * @param string $type 数据类型 ('comment' 或 'cover')
     * @param int $modelId 对应数据表的 ID
     */
    public function __construct(string $type, int $modelId)
    {
        $this->type = $type;
        $this->modelId = $modelId;
        $this->onQueue('slow'); // 统一推送到 slow 后台队列
    }

    public function handle(CommentImageService $imageService): void
    {
        if ($this->type === 'comment') {
            $this->handleComment($imageService);
        } elseif ($this->type === 'cover') {
            $this->handleCover();
        }
    }

    /**
     * 处理评论区图片
     */
    private function handleComment(CommentImageService $imageService): void
    {
        $comment = Comment::find($this->modelId);
        if (!$comment) {
            Log::warning("[图片优化] 未找到评论 ID: {$this->modelId}");
            return;
        }

        Log::info("[图片优化] 开始扫描评论 ID: {$this->modelId}");
        $changed = false;

        // 1. 升级头像
        if (!empty($comment->avatar)) {
            $newAvatar = $this->upgradeAvatar($comment->avatar, $imageService);
            if ($newAvatar !== $comment->avatar) {
                $comment->avatar = $newAvatar;
                $changed = true;
                Log::info("  - 评论 ID: {$this->modelId} 头像已升级 WebP");
            }
        }

        // 2. 升级配图
        $pictures = $comment->pictures ?? [];
        $picChanged = false;
        foreach ($pictures as $idx => $picData) {
            if (is_string($picData)) {
                $pictures[$idx] = $this->upgradeToDualPath($picData, 'comments', $imageService);
                $picChanged = true;
            }
        }
        if ($picChanged) {
            $comment->pictures = $pictures;
            $changed = true;
            Log::info("  - 评论 ID: {$this->modelId} 配图已升级双路径");
        }

        // 3. 升级表情包
        $emotes = $comment->emotes ?? [];
        $emoteChanged = false;
        foreach ($emotes as $key => $emoteData) {
            if (is_string($emoteData)) {
                $emotes[$key] = $this->upgradeToDualPath($emoteData, 'emotes', $imageService);
                $emoteChanged = true;
            }
        }
        if ($emoteChanged) {
            $comment->emotes = $emotes;
            $changed = true;
            Log::info("  - 评论 ID: {$this->modelId} 表情包已升级双路径");
        }

        if ($changed) {
            $comment->save();
            Log::info("[图片优化] 评论 ID: {$this->modelId} 数据保存成功！");
        } else {
            // 如果你觉得跳过的日志太多可以注释掉下面这行
            Log::debug("[图片优化] 评论 ID: {$this->modelId} 已经是最新格式，跳过。"); 
        }
    }

    /**
     * 处理封面图片
     */
    private function handleCover(): void
    {
        $cover = Cover::find($this->modelId);
        if (!$cover) {
            Log::warning("[图片优化] 未找到封面 ID: {$this->modelId}");
            return;
        }

        Log::info("[图片优化] 开始扫描封面 ID: {$this->modelId}");

        $absolutePath = storage_path('app/public/' . $cover->path);
        if (!file_exists($absolutePath)) {
            $absolutePath = storage_path('app/public/images/' . basename($cover->filename));
        }
        
        if (file_exists($absolutePath)) {
            $newPath = $this->optimizeImage($absolutePath);
            if ($newPath !== $absolutePath && file_exists($newPath) && filesize($newPath) > 0) {
                $cover->update([
                    'filename'  => basename($newPath),
                    'path'      => 'images/' . basename($newPath),
                    'mime_type' => @getimagesize($newPath)['mime'] ?? 'image/webp',
                    'size'      => filesize($newPath)
                ]);
                Log::info("[图片优化] 封面 ID: {$this->modelId} 已成功转换为 WebP 并更新数据库！");
            } else {
                Log::debug("[图片优化] 封面 ID: {$this->modelId} 无需转换或转换失败。");
            }
        } else {
            Log::error("[图片优化] 封面 ID: {$this->modelId} 原图物理文件丢失: {$absolutePath}");
        }
    }

    // --- 以下为通用的双路径辅助方法 ---

    private function upgradeAvatar($url, $imageService)
    {
        if (str_contains($url, 'hdslb.com')) return $imageService->processAvatar($url);

        if (str_starts_with($url, '/storage/')) {
            $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $absolutePath = storage_path('app/public/' . str_replace('/storage/', '', $url));
                $newPath = $this->optimizeImage($absolutePath);
                if ($newPath !== $absolutePath) {
                    return Storage::url(str_replace(storage_path('app/public/'), '', $newPath));
                }
            }
        }
        return $url;
    }

    private function upgradeToDualPath($url, $folder, $imageService)
    {
        if (str_contains($url, 'hdslb.com')) {
            if ($folder === 'comments') {
                $res = $imageService->processPictures([$url]);
                return $res[0] ?? $url;
            } else {
                $res = $imageService->processEmotes(['tmp' => $url]);
                return $res['tmp'] ?? $url;
            }
        }

        if (str_starts_with($url, '/storage/')) {
            $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
            if (in_array($ext, ['webp', 'gif'])) return ['webp' => $url, 'raw' => $url];

            $relativePath = str_replace('/storage/', '', $url);
            $absolutePath = storage_path("app/public/{$relativePath}");
            $webpUrl = $url;
            
            if (file_exists($absolutePath)) {
                $newPath = $this->optimizeImage($absolutePath);
                if ($newPath !== $absolutePath) {
                    $webpUrl = Storage::url(str_replace(storage_path('app/public/'), '', $newPath));
                }
            }
            return ['webp' => $webpUrl, 'raw'  => $url];
        }
        return ['webp' => $url, 'raw' => $url];
    }
}