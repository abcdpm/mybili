<?php

namespace Tests\Feature;

use App\Services\VideoManager\Contracts\VideoServiceInterface;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * 进度接口分块拉取测试（第7项优化）。
 */
class ProgressApiChunkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['id' => $i, 'title' => '视频' . $i, 'cover_image_url' => null];
        }

        $svc = Mockery::mock(VideoServiceInterface::class);
        $svc->shouldReceive('getVideosCache')->andReturn($items);
        $svc->shouldReceive('getVideosStat')->andReturn([
            'count' => 10, 'downloaded' => 4, 'invalid' => 1, 'valid' => 9, 'frozen' => 0,
        ]);
        $this->app->instance(VideoServiceInterface::class, $svc);
    }

    public function test_chunk_mode_returns_slice_with_has_more(): void
    {
        $resp = $this->getJson('/api/progress?offset=0&limit=3');
        $resp->assertStatus(200);
        $this->assertCount(3, $resp->json('data'));
        $this->assertSame(10, $resp->json('total'));
        $this->assertTrue($resp->json('has_more'));
        $this->assertSame(1, $resp->json('data.0.id'));
    }

    public function test_last_chunk_reports_no_more(): void
    {
        $resp = $this->getJson('/api/progress?offset=9&limit=3');
        $this->assertCount(1, $resp->json('data'), '末块应只剩 1 条');
        $this->assertFalse($resp->json('has_more'));
        $this->assertSame(10, $resp->json('data.0.id'));
    }

    public function test_default_mode_returns_full_list_for_compat(): void
    {
        $resp = $this->getJson('/api/progress');
        $resp->assertStatus(200);
        $this->assertCount(10, $resp->json('data'), '不传 limit 保持全量返回（兼容旧调用方）');
        $this->assertArrayNotHasKey('has_more', $resp->json());
        $this->assertSame(10, $resp->json('stat.count'));
    }

    public function test_limit_is_clamped(): void
    {
        $resp = $this->getJson('/api/progress?offset=0&limit=99999');
        $this->assertCount(10, $resp->json('data'), 'limit 超限时按数据实际量返回');
        $this->assertFalse($resp->json('has_more'));
    }
}