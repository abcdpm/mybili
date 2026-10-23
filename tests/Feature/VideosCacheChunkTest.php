<?php

namespace Tests\Feature;

use App\Services\VideoManager\VideoService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 视频列表缓存 Redis List 分块（批F·P0 内存优化）测试。
 *
 * 用内存 fake 客户端替代 Redis：覆盖分块写入/读取、跨块切片、
 * 旧键兼容回退、总量统计。核心断言：切片结果与全量 array_slice 完全一致。
 */
class VideosCacheChunkTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, array<int, string>> */
    private array $lists = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [];
        $this->lists = [];

        $store = &$this->store;
        $lists = &$this->lists;

        $fake = new class($store, $lists)
        {
            public function __construct(private array &$store, private array &$lists) {}

            public function get($key)
            {
                return $this->store[$key] ?? false;
            }

            public function set($key, $value, $opts = [])
            {
                $this->store[$key] = $value;

                return true;
            }

            public function del(...$keys)
            {
                foreach ($keys as $k) {
                    unset($this->store[$k], $this->lists[$k]);
                }

                return true;
            }

            public function rPush($key, $value)
            {
                $this->lists[$key][] = $value;

                return count($this->lists[$key]);
            }

            public function lLen($key)
            {
                return count($this->lists[$key] ?? []);
            }

            public function lIndex($key, $index)
            {
                return $this->lists[$key][$index] ?? false;
            }

            public function expire($key, $ttl)
            {
                return true;
            }
        };

        Redis::shouldReceive('connection')->andReturn(new class($fake)
        {
            public function __construct(private object $fake) {}

            public function client()
            {
                return $this->fake;
            }
        });
    }

    /** 生成 n 条轻量视频数据 */
    private function makeVideos(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['id' => $i, 'title' => "视频{$i}", 'view' => $i * 10];
        }

        return $out;
    }

    public function test_chunked_write_and_full_read_roundtrip(): void
    {
        $videos = $this->makeVideos(1200); // 500/块 → 3 块
        app(VideoService::class)->updateVideosCache($videos);

        // 分块写入：3 个块 + 总量键
        $this->assertSame(3, count($this->lists['video_list:v2'] ?? []));
        $this->assertSame('1200', $this->store['video_list:v2:total']);

        // 全量读取逐块拼装后与原数据一致
        $read = app(VideoService::class)->getVideosCache();
        $this->assertCount(1200, $read);
        $this->assertSame($videos, $read);
    }

    public function test_slice_across_chunk_boundaries_matches_array_slice(): void
    {
        $videos = $this->makeVideos(1200);
        $svc = app(VideoService::class);
        $svc->updateVideosCache($videos);

        // 跨块切片（450-749 跨越块0/块1）与尾部切片均须与 array_slice 一致
        $this->assertSame(array_slice($videos, 450, 300), $svc->getVideosCacheSlice(450, 300));
        $this->assertSame(array_slice($videos, 0, 10), $svc->getVideosCacheSlice(0, 10));
        $this->assertSame(array_slice($videos, 1195, 100), $svc->getVideosCacheSlice(1195, 100), '越界应截断到末尾');
        $this->assertSame(array_slice($videos, 999, 1), $svc->getVideosCacheSlice(999, 1));
    }

    public function test_total_is_exact(): void
    {
        $svc = app(VideoService::class);
        $svc->updateVideosCache($this->makeVideos(777));
        $this->assertSame(777, $svc->getVideosCacheTotal());
    }

    public function test_legacy_single_key_fallback(): void
    {
        // 仅旧键（升级过渡期）：读取与切片都应回退旧键
        $videos = $this->makeVideos(300);
        $this->store['video_list'] = json_encode($videos);

        $svc = app(VideoService::class);
        $this->assertSame($videos, $svc->getVideosCache());
        $this->assertSame(array_slice($videos, 100, 50), $svc->getVideosCacheSlice(100, 50));
    }

    public function test_v2_preferred_over_legacy(): void
    {
        $legacy = $this->makeVideos(10);
        $this->store['video_list'] = json_encode($legacy);
        $v2 = $this->makeVideos(1200);
        app(VideoService::class)->updateVideosCache($v2);

        $this->assertCount(1200, app(VideoService::class)->getVideosCache(), 'v2 分块应优先于旧键');
    }
}
