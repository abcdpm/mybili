<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 收藏夹轻量列表新增字段（第21项：播放清单 B站卡片）测试。
 *
 * 验证 upper_name（UP主名）与 danmaku_count（弹幕数聚合）随列表一次查询带出，
 * 供播放清单卡片渲染（封面/标题/UP主/播放量·弹幕/时长），避免前端 N+1。
 */
class FavVideosPlaylistFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('favorite_lists')->insert([
            'id' => 91, 'title' => '测试收藏夹', 'cover' => 'c', 'ctime' => now(), 'mtime' => now(),
            'media_count' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // UP 主
        DB::table('upper')->insert([
            ['mid' => 2001, 'name' => '娱乐玩家mini', 'face' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([[501, 2001], [502, 2002]] as [$id, $upperId]) {
            Video::query()->create([
                'id' => $id, 'link' => 'l', 'title' => 'V'.$id, 'intro' => '', 'cover' => 'https://e/c.jpg',
                'bvid' => 'BV'.$id, 'pubtime' => '2025-06-14 12:00:00', 'upper_id' => $upperId,
            ]);
            DB::table('favorite_list_videos')->insert([
                'favorite_list_id' => 91, 'video_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // 弹幕数：501=8 条，502=20 条
        DB::table('danmaku_files')->insert([
            ['cid' => 50101, 'video_id' => 501, 'count' => 8, 'path' => 'p', 'updated_at' => now()],
            ['cid' => 50201, 'video_id' => 502, 'count' => 20, 'path' => 'p', 'updated_at' => now()],
        ]);
    }

    private function invokeFav(int $favId): array
    {
        $method = new ReflectionMethod(VideoService::class, 'queryFavVideosLightweight');
        $method->setAccessible(true);

        return $method->invoke(app(VideoService::class), $favId);
    }

    public function test_upper_name_is_included(): void
    {
        $list = $this->invokeFav(91);

        $v501 = collect($list)->firstWhere('id', 501);
        $this->assertSame('娱乐玩家mini', $v501['upper_name']);

        // 无对应 UP 主记录时输出 null（leftJoin 语义），不报错
        $v502 = collect($list)->firstWhere('id', 502);
        $this->assertNull($v502['upper_name']);
    }

    public function test_danmaku_count_is_aggregated_per_video(): void
    {
        $list = $this->invokeFav(91);

        $this->assertSame(8, (int) collect($list)->firstWhere('id', 501)['danmaku_count']);
        $this->assertSame(20, (int) collect($list)->firstWhere('id', 502)['danmaku_count']);
    }

    public function test_cover_duration_view_fields_available_for_card(): void
    {
        $list = $this->invokeFav(91);
        $row = collect($list)->firstWhere('id', 501);

        // B站卡片所需字段齐备：封面/标题/时长/播放量
        $this->assertArrayHasKey('cover_image_url', $row);
        $this->assertArrayHasKey('title', $row);
        $this->assertArrayHasKey('duration', $row);
        $this->assertArrayHasKey('view', $row);
    }
}
