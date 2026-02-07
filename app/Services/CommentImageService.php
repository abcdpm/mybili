<?php

namespace App\Services;

use App\Models\Emote;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CommentImageService
{
    /**
     * 处理评论内容中的表情包
     */
    public function processEmotes(array $emotes): array
    {
        $localEmotes = [];
        
        foreach ($emotes as $text => $url) {
            $hash = md5($url);
            
            // 1. 检查数据库是否已存在 (复用)
            $cached = Emote::where('url_hash', $hash)->first();
            if ($cached) {
                // 如果存在，直接使用，不管后缀是什么
                $localEmotes[$text] = Storage::url('emotes/' . $cached->filename);
                continue;
            }

            // 2. 下载并处理
            $filename = $this->downloadAndSave($url, 'emotes');
            
            if ($filename) {
                try {
                     Emote::firstOrCreate(
                        ['url_hash' => $hash],
                        [
                            'filename' => $filename,
                            'text' => $text,
                        ]
                    );
                    $localEmotes[$text] = Storage::url('emotes/' . $filename);
                } catch (\Exception $e) {
                     // 忽略并发冲突
                     $localEmotes[$text] = $url;
                }
            } else {
                $localEmotes[$text] = $url;
            }
        }
        
        return $localEmotes;
    }

    /**
     * 处理评论配图
     */
    public function processPictures(array $pictures): array
    {
        $localPictures = [];
        foreach ($pictures as $url) {
            $filename = $this->downloadAndSave($url, 'comments');
            if ($filename) {
                $localPictures[] = Storage::url('comments/' . $filename);
            }
        }
        return $localPictures;
    }

    /**
     * 下载图片并保存 (智能动图修复版)
     */
    private function downloadAndSave(string $url, string $folder): ?string
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
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])->get($targetUrl);

            // 如果 WebP 下载失败（404），回退到原始 URL
            if ($response->failed() && $targetUrl !== $url) {
                $response = Http::withHeaders([
                    'Referer' => 'https://www.bilibili.com/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
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
            elseif (str_contains($contentType, 'image/avif')) $extension = 'avif';            
            
            // 3. 保存 (使用原始 URL 的 hash 作为文件名，这样 logic 不需要改)
            $filename = md5($url) . '.' . $extension;
            $path = $folder . '/' . $filename;

            Storage::disk('public')->put($path, $content);            
            
            return $filename;

        } catch (\Exception $e) {
            Log::error("图片处理异常: " . $e->getMessage());
            return null;
        }
    }
}