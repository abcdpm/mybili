<?php

namespace App\Services;

use App\Models\Emote;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use App\Traits\ImageOptimizerTrait;

class CommentImageService
{
    use ImageOptimizerTrait; 

    /**
     * 处理评论内容中的表情包（返回双路径对象）
     */
    public function processEmotes(array $emotes): array
    {
        $localEmotes = [];
        foreach ($emotes as $text => $url) {
            $hash = md5($url);
            
            // 1. 检查数据库是否已存在 (复用)
            $cached = Emote::where('url_hash', $hash)->first();
            
            if ($cached) {
                // 如果命中缓存，也构造成双路径格式
                $base = pathinfo($cached->filename, PATHINFO_FILENAME);
                $localEmotes[$text] = [
                    'webp' => '/storage/emotes/' . $base . '.webp',
                    'raw'  => '/storage/emotes/' . $cached->filename 
                ];
                continue;
            }

            // 2. 下载并处理
            $paths = $this->downloadAndSave($url, 'emotes');
            if ($paths) {
                try {
                     Emote::firstOrCreate(
                        ['url_hash' => $hash],
                        ['filename' => $paths['webp'], 'text' => $text]
                    );
                    // 存入相对路径的双对象
                    $localEmotes[$text] = [
                        'webp' => '/storage/emotes/' . $paths['webp'],
                        'raw'  => '/storage/emotes/' . $paths['raw']
                    ];
                } catch (\Exception $e) {
                     $localEmotes[$text] = $url;
                }
            } else {
                $localEmotes[$text] = $url;
            }
        }
        return $localEmotes;
    }

    /**
     * 处理评论配图（返回双路径对象）
     */
    public function processPictures(array $pictures): array
    {
        $localPictures = [];
        foreach ($pictures as $url) {
            $paths = $this->downloadAndSave($url, 'comments');
            if ($paths) {
                $localPictures[] = [
                    'webp' => '/storage/comments/' . $paths['webp'],
                    'raw'  => '/storage/comments/' . $paths['raw']
                ];
            }
        }
        return $localPictures;
    }

    /**
     * 处理评论区用户头像（仅返回 WebP 相对路径）
     */
    public function processAvatar(string $url): string
    {
        if (empty($url)) {
            return $url;
        }

        $paths = $this->downloadAndSave($url, 'avatars');
        if ($paths) {
            // 头像是纯 varchar 字段，直接返回相对路径的 WebP
            return '/storage/avatars/' . $paths['webp'];
        }

        return $url;
    }

    /**
     * 下载图片并触发优化，返回双格式文件名
     */
    private function downloadAndSave(string $url, string $folder): ?array
    {
        try {
            $targetUrl = $url;            
            // 【核心修复】B站表情包策略：如果原链接是 png，尝试替换为 webp 以获取动图
            // 很多 B 站 API 返回的是 .png 静态图，但其实服务器上有 .webp 动图
            // TODO 当前获取的仍然时.png静态图
            if (str_contains($url, '.hdslb.com/bfs/emote/') || str_contains($url, '.hdslb.com/bfs/garb/')) {
                if (str_ends_with($url, '.png')) {
                    $targetUrl = substr($url, 0, -4) . '.webp';                    
                }
            }

            // 1. 发起请求
            $response = Http::withHeaders([
                'Referer' => 'https://www.bilibili.com/',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->get($targetUrl);

            // 如果 WebP 下载失败（404），回退到原始 URL
            if ($response->failed() && $targetUrl !== $url) {
                $response = Http::withHeaders([
                    'Referer' => 'https://www.bilibili.com/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])->get($url);
            }

            if ($response->failed()) {
                Log::warning("图片下载失败 [{$response->status()}]: $url");
                return null;
            }

            $content = $response->body();
            $contentType = $response->header('Content-Type');            

            // 2. 识别真实后缀名
            $extension = 'jpg'; 
            if (str_contains($contentType, 'image/webp')) $extension = 'webp';
            elseif (str_contains($contentType, 'image/gif')) $extension = 'gif';
            elseif (str_contains($contentType, 'image/png')) $extension = 'png';
            // 【移除】移除了 avif 的探查
            
            // 3. 先保存原图
            $rawFilename = md5($url) . '.' . $extension;
            $path = $folder . '/' . $rawFilename;
            Storage::disk('public')->put($path, $content);

            // === 【新增】累加评论图片/表情包大小到缓存 ===
            // 因为 $content 是字符串格式的二进制流，strlen 就是准确的字节大小
            Cache::increment('stat_images_size', strlen($content));

            // 4. 触发 WebP 转换
            $fullPath = Storage::disk('public')->path($path);
            $optimizedFullPath = $this->optimizeImage($fullPath);
            $webpFilename = basename($optimizedFullPath);

            // 【核心修改】向外抛出双文件名字典
            return [
                'webp' => $webpFilename,
                'raw'  => $rawFilename
            ];

        } catch (\Exception $e) {
            Log::error("图片处理异常: " . $e->getMessage());
            return null;
        }
    }
}