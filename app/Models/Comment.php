<?php

namespace App\Models;

use App\Services\Comment\EmoteResolver;
use App\Traits\UsesCommentsDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    use UsesCommentsDatabase;

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
     * 评论用户信息（归一化存储）。
     * 新版评论行内不再冗余 uname/avatar，统一由本关联提供；
     * 历史数据行内仍有值，可无损兜底。
     */
    public function commentUser(): BelongsTo
    {
        return $this->belongsTo(CommentUser::class, 'mid', 'mid');
    }

    /**
     * 【核心拦截】重写输出给前端的 JSON 数据
     * 强制将历史遗留的 B 站图片外链全部转换为本地代理路由，彻底阻断前端直连；
     * 并兼容两种存储形态（旧版行内完整映射 / 新版 key 列表），
     * 输出结构保持完全一致，前端无需改动。
     */
    public function toArray()
    {
        $array = parent::toArray();

        // 1. 用户信息：新版存 comment_users（行内为空），旧版行内有值兜底
        if (empty($array['uname']) || empty($array['avatar'])) {
            $user = $this->relationLoaded('commentUser') ? $this->getRelation('commentUser') : $this->commentUser;
            if ($user) {
                $array['uname'] = ($array['uname'] ?? '') !== '' ? $array['uname'] : $user->uname;
                $array['avatar'] = ($array['avatar'] ?? '') !== '' ? $array['avatar'] : $user->avatar;
            }
        }
        $array['uname'] = $array['uname'] ?? '';
        $array['avatar'] = $array['avatar'] ?? '';

        // 2. 头像
        if (! empty($array['avatar']) && str_starts_with($array['avatar'], '/storage/')) {
            $array['avatar'] = asset($array['avatar']);
        }

        // 3. 评论配图
        $array['pictures'] = $this->formatDualPaths($array['pictures'] ?? []);

        // 4. 表情包（兼容旧版完整映射 / 新版 key 列表）
        $array['emotes'] = $this->formatEmotes($array['emotes'] ?? []);

        return $array;
    }

    /**
     * 表情包双格式兼容：
     *   - 旧版：{"[doge]": {"webp": "...", "raw": "..."}}（或字符串 URL）→ 原样输出；
     *   - 新版：["[doge]", ...]（key 列表）→ 经 EmoteResolver 还原为完整映射。
     */
    private function formatEmotes(mixed $emotes): array
    {
        if (! is_array($emotes)) {
            return [];
        }

        if (array_is_list($emotes)) {
            $emotes = app(EmoteResolver::class)->expand($emotes);
        }

        return $this->formatDualPaths($emotes, true);
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
