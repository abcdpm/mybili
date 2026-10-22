<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Services\Danmaku\DanmakuFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 弹幕播放接口缓存与 HTTP 协商缓存测试（第3项优化）。
 */
class DanmakuApiCacheTest extends TestCase
{
    use RefreshDatabase;

    private DanmakuFileStore $store;
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->tmpPath = sys_get_temp_dir() . '/mybili_danmaku_api_' . uniqid();
        config(['danmaku.path' => $this->tmpPath]);
        $this->store = app(DanmakuFileStore::class);

        DB::table('videos')->insert([
            'id' => 555001, 'link' => 'l', 'title' => 't', 'intro' => '', 'cover' => 'c',
            'bvid' => 'BVAPI', 'pubtime' => '2025-06-14 12:00:00', 'cache_image' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpPath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpPath);
        parent::tearDown();
    }

    public function test_danmaku_api_returns_data_and_etag(): void
    {
        $this->store->save(10001, [
            ['id' => 1, 'progress' => 1000, 'mode' => 1, 'color' => 255, 'content' => '第一条弹幕'],
        ], 555001);

        $resp = $this->getJson('/api/danmaku?id=10001');
        $resp->assertStatus(200);
        $resp->assertJson(['code' => 0]);
        $this->assertCount(1, $resp->json('data'));
        $this->assertSame('第一条弹幕', $resp->json('data.0.text'));
        $this->assertNotEmpty($resp->headers->get('ETag'), '响应应携带 ETag');
        $this->assertStringContainsString('max-age', (string) $resp->headers->get('Cache-Control'));
    }

    public function test_etag_not_modified_returns_304(): void
    {
        $this->store->save(10002, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => 'x'],
        ], 555001);

        $first  = $this->getJson('/api/danmaku?id=10002');
        $etag   = $first->headers->get('ETag');

        $second = $this->getJson('/api/danmaku?id=10002', ['If-None-Match' => $etag]);
        $second->assertStatus(304);
    }

    public function test_cache_and_etag_refresh_after_update(): void
    {
        $this->store->save(10003, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '初始'],
        ], 555001);

        $first = $this->getJson('/api/danmaku?id=10003');
        $first->assertStatus(200);
        $oldEtag = $first->headers->get('ETag');

        // 弹幕更新（新增一条）→ 指纹变化，旧 ETag 不再命中，且数据包含新弹幕
        $this->store->save(10003, [
            ['id' => 2, 'progress' => 100, 'mode' => 1, 'color' => 255, 'content' => '更新后新增'],
        ], 555001);

        $second = $this->getJson('/api/danmaku?id=10003', ['If-None-Match' => $oldEtag]);
        $second->assertStatus(200);
        $this->assertNotSame($oldEtag, $second->headers->get('ETag'), '内容变更后 ETag 必须变化');
        $texts = array_column($second->json('data'), 'text');
        $this->assertContains('更新后新增', $texts, '缓存不得遮蔽新弹幕（无脏读）');
        $this->assertContains('初始', $texts);
    }

    public function test_repeated_requests_hit_converted_cache(): void
    {
        $this->store->save(10004, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '缓存测试'],
        ], 555001);

        $a = $this->getJson('/api/danmaku?id=10004');
        $b = $this->getJson('/api/danmaku?id=10004');
        $this->assertSame($a->headers->get('ETag'), $b->headers->get('ETag'));
        $this->assertEquals($a->json('data'), $b->json('data'), '相同内容两次响应一致');

        // 转换结果确实进入了缓存层（缓存键使用裸指纹，ETag 头带引号包裹）
        $rawEtag = trim((string) $a->headers->get('ETag'), '"');
        $this->assertIsArray(cache()->get('danmaku:conv:10004:' . $rawEtag), '转换结果应写入缓存');
    }

    public function test_missing_cid_returns_error(): void
    {
        $resp = $this->getJson('/api/danmaku');
        $resp->assertStatus(200);
        $this->assertSame(1, $resp->json('code'));
    }
}