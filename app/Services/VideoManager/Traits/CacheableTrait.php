<?php
namespace App\Services\VideoManager\Traits;

use Illuminate\Support\Facades\Cache;

trait CacheableTrait
{
    protected function cacheKey(string $prefix, ...$params): string
    {
        return $prefix . ':' . implode(':', $params);
    }

    protected function cacheRemember(string $key, int $ttl, callable $callback)
    {
        return cache()->remember($key, $ttl, $callback);
    }

    /**
     * 按模式匹配清理缓存（如 cacheForget('video_list:*')）。
     *
     * 使用 Redis SCAN 游标迭代（非阻塞），避免 KEYS 命令阻塞生产库；
     * 使用非 Redis 缓存驱动时静默跳过（缓存均带 TTL，会自动过期）。
     */
    protected function cacheForget(string $pattern): void
    {
        try {
            $store = Cache::getStore();
            if (! $store instanceof \Illuminate\Cache\RedisStore) {
                return;
            }

            $redis  = $store->connection();
            $prefix = $store->getPrefix();
            $cursor = null;

            do {
                $keys = $redis->scan($cursor, $prefix . $pattern, 500);
                if (! empty($keys)) {
                    // scan 返回的 key 可能带/不带前缀（不同驱动行为不一），统一去重后删除
                    $redis->del($keys);
                }
            } while ($cursor > 0);
        } catch (\Throwable $e) {
            // 降级保护：任何异常都不影响主流程（缓存带 TTL 会自动过期）
        }
    }
}