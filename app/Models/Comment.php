<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ctime' => 'datetime',
        'pictures' => 'array', // 自动转换 JSON
        'emotes' => 'array',   // 自动转换 JSON
        'is_top' => 'boolean',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'root', 'rpid')->orderBy('like', 'desc'); // 子评论按点赞排序
    }

    /**
     * 【核心拦截】重写输出给前端的 JSON 数据
     * 强制将历史遗留的 B 站图片外链全部转换为本地代理路由，彻底阻断前端直连
     */
    public function toArray()
    {
        $array = parent::toArray();

        // 1. 拦截评论区头像 (avatar)
        if (!empty($array['avatar']) && str_contains($array['avatar'], 'hdslb.com')) {
            $array['avatar'] = url('/api/image/proxy?type=avatar&url=' . urlencode($array['avatar']));
        } elseif (!empty($array['avatar']) && str_starts_with($array['avatar'], '/storage/')) {
            $array['avatar'] = asset($array['avatar']); // 补齐历史遗留的无 http 前缀数据
        }

        // 2. 拦截评论配图 (pictures)
        if (!empty($array['pictures']) && is_array($array['pictures'])) {
            foreach ($array['pictures'] as $idx => $url) {
                if (str_contains($url, 'hdslb.com')) {
                    $array['pictures'][$idx] = url('/api/image/proxy?type=comment&url=' . urlencode($url));
                } elseif (str_starts_with($url, '/storage/')) {
                    $array['pictures'][$idx] = asset($url);
                }
            }
        }

        // 3. 拦截表情包 (emotes)
        if (!empty($array['emotes']) && is_array($array['emotes'])) {
            foreach ($array['emotes'] as $key => $url) {
                if (str_contains($url, 'hdslb.com')) {
                    $array['emotes'][$key] = url('/api/image/proxy?type=emote&url=' . urlencode($url));
                } elseif (str_starts_with($url, '/storage/')) {
                    $array['emotes'][$key] = asset($url);
                }
            }
        }

        return $array;
    }
}