<?php

namespace Tests\Feature;

use App\Models\Cover;
use App\Models\Video;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 视频轻量列表（进度页缓存数据源）测试。
 *
 * 验证旧版 N+1（2.4 万视频 ≈ 4.8 万次封面查询）已修复为单条 JOIN 查询，
 * 且输出结构与旧版完全一致。
 */
class VideoListLightweightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    private function makeVideo(int $id, ?string $coverPath = null): void
    {
        Video::query()->create([
            'id' => $id,
            'link' => 'https://bilibili.com/video/BV' . $id,
            'title' => '视频' . $id,
            'intro' => '',
            'cover' => 'https://example.com/c.jpg',
            'bvid' => 'BV' . $id,
            'pubtime' => '2025-06-14 12:00:00',
        ]);

        if ($coverPath !== null) {
            $cover = Cover::query()->create([
                'url' => 'https://example.com/c' . $id . '.jpg',
                'type' => 'video',
                'filename' => 'c' . $id . '.jpg',
                'path' => $coverPath,
            ]);
            DB::table('coverables')->insert([
                'cover_id' => $cover->id,
                'coverable_id' => $id,
                'coverable_type' => Video::class,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function invokeLightweight(): array
    {
        $method = new ReflectionMethod(VideoService::class, 'getAllVideosLightweight');
        $method->setAccessible(true);

        return $method->invoke(app(VideoService::class));
    }

    public function test_output_shape_matches_legacy(): void
    {
        $this->makeVideo(1, 'images/cover1.webp');
        $this->makeVideo(2, null);

        $list = $this->invokeLightweight();

        $this->assertCount(2, $list);
        $row = collect($list)->firstWhere('id', 1);
        $this->assertSame(Storage::url('images/cover1.webp'), $row['cover_image_url']);
        $this->assertSame(strtotime('2025-06-14 12:00:00'), $row['pubtime'], 'pubtime 应输出 Unix 时间戳');
        $this->assertNull($row['fav_time']);
        $this->assertSame('视频1', $row['title']);
        $this->assertSame('BV1', $row['bvid']);
        $this->assertArrayHasKey('duration', $row);
        $this->assertArrayHasKey('view', $row);

        $row2 = collect($list)->firstWhere('id', 2);
        $this->assertNull($row2['cover_image_url'], '无封面视频应输出 null');
    }

    public function test_no_n_plus_one_queries(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->makeVideo(100 + $i, 'images/cover' . $i . '.webp');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->invokeLightweight();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, count($queries), '批量取封面必须是单条 JOIN 查询，不能逐行查询（N+1）');
    }

    public function test_soft_deleted_videos_excluded(): void
    {
        $this->makeVideo(301, null);
        Video::query()->find(301)->delete();

        $list = $this->invokeLightweight();
        $this->assertCount(0, $list);
    }
}