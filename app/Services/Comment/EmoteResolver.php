<?php

namespace App\Services\Comment;

use App\Models\Emote;

/**
 * 表情包路径解析器。
 *
 * 评论的表情包映射已归一化为 key 列表（如 ["[doge]"]）存储，
 * 展示时通过本服务按 text 反查 emotes 表还原为
 * {"[doge]": {"webp": "...", "raw": "..."}} 的完整映射，
 * 输出结构与旧版完全一致，前端无需改动。
 *
 * 兼容性：旧版评论行内存储的完整映射仍按原样输出（不经过本服务）。
 */
class EmoteResolver
{
    /** @var array<string, array{webp: string, raw: string}|null> */
    private array $cache = [];

    /**
     * 批量预热解析缓存（一次查询），避免序列化评论列表时逐条查询。
     *
     * @param  array<int, string>  $texts
     */
    public function prime(array $texts): void
    {
        $missing = array_values(array_unique(array_filter($texts, fn ($t) => is_string($t) && $t !== '' && ! array_key_exists($t, $this->cache))));
        if (empty($missing)) {
            return;
        }

        $rows = Emote::query()->whereIn('text', $missing)->get(['text', 'filename']);
        foreach ($missing as $text) {
            $this->cache[$text] = null;
        }
        foreach ($rows as $row) {
            $this->cache[$row->text] = $this->toDualPaths($row->filename);
        }
    }

    /**
     * 解析单个表情文本；未收录时返回 null。
     *
     * @return array{webp: string, raw: string}|null
     */
    public function resolve(string $text): ?array
    {
        if (! array_key_exists($text, $this->cache)) {
            $this->prime([$text]);
        }

        return $this->cache[$text] ?? null;
    }

    /**
     * 将 key 列表展开为完整映射（解析不到的 key 会被跳过）。
     *
     * @param  array<int, string>  $keys
     * @return array<string, array{webp: string, raw: string}>
     */
    public function expand(array $keys): array
    {
        $this->prime(array_values(array_filter($keys, 'is_string')));

        $result = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }
            $paths = $this->cache[$key] ?? null;
            if ($paths !== null) {
                $result[$key] = $paths;
            }
        }

        return $result;
    }

    /**
     * 根据 emotes.filename 生成与 CommentImageService 一致的双路径结构（相对路径，输出层再转绝对地址）。
     *
     * @return array{webp: string, raw: string}
     */
    private function toDualPaths(string $filename): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return [
            'webp' => '/storage/emotes/' . $base . '.webp',
            'raw'  => '/storage/emotes/' . $filename,
        ];
    }
}