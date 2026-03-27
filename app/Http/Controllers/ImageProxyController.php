<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\CommentImageService;

class ImageProxyController extends Controller
{
    public function __construct(protected CommentImageService $imageService)
    {
    }

    /**
     * 智能图像本地化路由：优先探查本地，无则下载
     */
    public function proxy(Request $request)
    {
        $url = $request->query('url');
        $type = $request->query('type', 'avatar'); 

        // 安全校验
        if (!$url || !str_contains($url, 'hdslb.com')) {
            return abort(404);
        }

        // 1. 尝试快速探针：查找本地磁盘是否已存在该文件的优化版本
        $hash = md5($url);
        $folders = [
            'avatar'  => 'avatars',
            'comment' => 'comments',
            'emote'   => 'emotes',
        ];
        $folder = $folders[$type] ?? 'images';

        // 严格按照体积从优到劣的顺序探测本地文件
        // 严格按照体积从优到劣的顺序探测本地文件
        $extensions = ['avif', 'webp', 'gif', 'png', 'jpg'];
        foreach ($extensions as $ext) {
            $path = "{$folder}/{$hash}.{$ext}";
            
            // 【核心修复】：不仅要文件存在，大小还必须大于 0 字节！
            if (Storage::disk('public')->exists($path) && Storage::disk('public')->size($path) > 0) {
                // 如果本地已存在且完好，直接 302 重定向
                return redirect(asset(Storage::url($path)));
            }
        }

        // 2. 探查失败：说明这是漏网之鱼，触发【实时下载与转码流水线】
        if ($type === 'avatar') {
            $finalUrl = $this->imageService->processAvatar($url);
            return redirect($finalUrl);
        } elseif ($type === 'emote') {
            $res = $this->imageService->processEmotes(['tmp' => $url]);
            return redirect($res['tmp']);
        } elseif ($type === 'comment') {
            $res = $this->imageService->processPictures([$url]);
            return redirect($res[0] ?? $url);
        }

        // 3. 兜底方案（仅在网络极度异常下载失败时才会发生），退回原 B 站链接
        return redirect($url);
    }
}