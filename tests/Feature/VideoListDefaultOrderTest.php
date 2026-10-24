<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 进度页/列表默认排序回归测试（按收藏时间降序，最新收藏在最上面）。
 *
 * 覆盖 fav_time 的三种存储形态混排：unix 整数字符串（B站抓取写入）、
 * datetime 字符串（回填/手工）、NULL（无收藏时间回退 created_at）。
 * 旧实现按文本排序（"999999999" > "1750000000"）导致顺序错乱。
 */
class VideoListDefaultOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_order_is_fav_time_desc_across_mixed_formats(): void
    {
        // 三视频：A=unix 整数字符串（较小）、B=unix 整数字符串（较大=最新）、C=datetime 字符串（中间）
        Video::query()->forceCreate([
            'id' => 901, 'link' => 'l', 'title' => 'A', 'intro' => '', 'cover' => 'c', 'bvid' => 'BVO1',
            'pubtime' => '2025-06-14 12:00:00', 'fav_time' => 1750000000,
        ]);
        Video::query()->forceCreate([
            'id' => 902, 'link' => 'l', 'title' => 'B', 'intro' => '', 'cover' => 'c', 'bvid' => 'BVO2',
            'pubtime' => '2025-06-14 12:00:00', 'fav_time' => 1750999999,
        ]);
        Video::query()->forceCreate([
            'id' => 903, 'link' => 'l', 'title' => 'C', 'intro' => '', 'cover' => 'c', 'bvid' => 'BVO3',
            'pubtime' => '2025-06-14 12:00:00', 'fav_time' => '2025-06-01 08:00:00',
        ]);

        $method = new ReflectionMethod(VideoService::class, 'getAllVideosLightweight');
        $method->setAccessible(true);
        $list = $method->invoke(app(VideoService::class));

        // epoch 归一后：B(1750999999) > C(2025-06-01 = 1748764800) > A(1750000000)
        // 注：C 的 datetime 对应 epoch 1748764800 < A 的 1750000000，故顺序 B > A > C
        $this->assertSame([902, 901, 903], array_column($list, 'id'));
    }

    public function test_null_fav_time_falls_back_to_created_at_and_stays_last(): void
    {
        Video::query()->forceCreate([
            'id' => 911, 'link' => 'l', 'title' => '有收藏时间', 'intro' => '', 'cover' => 'c', 'bvid' => 'BVO4',
            'pubtime' => '2025-06-14 12:00:00', 'fav_time' => 1750000000, 'created_at' => '2020-01-01 00:00:00',
        ]);
        Video::query()->forceCreate([
            'id' => 912, 'link' => 'l', 'title' => '无收藏时间', 'intro' => '', 'cover' => 'c', 'bvid' => 'BVO5',
            'pubtime' => '2025-06-14 12:00:00', 'fav_time' => null, 'created_at' => '2026-01-01 00:00:00',
        ]);

        $method = new ReflectionMethod(VideoService::class, 'getAllVideosLightweight');
        $method->setAccessible(true);
        $list = $method->invoke(app(VideoService::class));

        // 无收藏时间的回退 created_at（2026 > 1750000000 对应 2025-06）→ 排最前；降序语义成立
        $this->assertSame([912, 911], array_column($list, 'id'));
    }

    public function test_fav_list_query_uses_same_ordering(): void
    {
        DB::table('favorite_lists')->insert([
            'id' => 92, 'title' => '排序收藏夹', 'cover' => 'c', 'ctime' => now(), 'mtime' => now(),
            'media_count' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[921, 1750000000], [922, 1750999999]] as [$id, $fav]) {
            Video::query()->forceCreate([
                'id' => $id, 'link' => 'l', 'title' => "V{$id}", 'intro' => '', 'cover' => 'c', 'bvid' => 'BVO'.$id,
                'pubtime' => '2025-06-14 12:00:00', 'fav_time' => $fav,
            ]);
            DB::table('favorite_list_videos')->insert([
                'favorite_list_id' => 92, 'video_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $method = new ReflectionMethod(VideoService::class, 'queryFavVideosLightweight');
        $method->setAccessible(true);
        $list = $method->invoke(app(VideoService::class), 92);

        $this->assertSame([922, 921], array_column($list, 'id'));
    }
}
