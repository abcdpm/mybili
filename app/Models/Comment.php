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

        // 1. 头像
        if (!empty($array['avatar']) && str_starts_with($array['avatar'], '/storage/')) {
            $array['avatar'] = asset($array['avatar']);
        }

        // 2. 评论配图
        $array['pictures'] = $this->formatDualPaths($array['pictures'] ?? []);

        // 3. 表情包
        $array['emotes'] = $this->formatDualPaths($array['emotes'] ?? [], true);

        return $array;
    }

    private function formatDualPaths(array $items, bool $isAssoc = false): array
    {
        foreach ($items as $key => $item) {
            if (is_array($item) && isset($item['webp'])) {
                $items[$key]['webp'] = str_starts_with($item['webp'], '/storage/') ? asset($item['webp']) : $item['webp'];
                if (isset($item['raw'])) {
                    $items[$key]['raw'] = str_starts_with($item['raw'], '/storage/') ? asset($item['raw']) : $item['raw'];
                }
            } elseif (is_string($item) && str_starts_with($item, '/storage/')) {
                // 兼容未跑清理脚本前的老字符串数据
                $items[$key] = asset($item);
            }
        }
        return $items;
    }
}