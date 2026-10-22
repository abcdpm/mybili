<?php

namespace Tests\Feature;

use App\Models\DanmakuFile;
use App\Models\Video;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 视频列表排序（第 18 项）测试。
 *
 * 覆盖：播放量/发布时间/收藏时间/时长/弹幕数排序、升降序、非法排序键忽略。
 */
class VideoListSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Video::query()->forceCreate([
            'id' => 701, 'link' => 'l', 'title' => 'A', 'intro' => '', 'cover' => 'c',
            'bvid' => 'BVS1', 'pubtime' => '2025-01-01 00:00:00', 'duration' => 100,
            'video_downloaded_num' => 0, 'invalid' => 0, 'frozen' => 0, 'view' => 50, 'fav_time' => '2025-06-01 00:00:00',
        ]);
        Video::query()->forceCreate([
            'id' => 702, 'link' => 'l', 'title' => 'B', 'intro' => '', 'cover' => 'c',
            'bvid' => 'BVS2', 'pubtime' => '2025-03-01 00:00:00', 'duration' => 300,
            'video_downloaded_num' => 0, 'invalid' => 0, 'frozen' => 0, 'view' => 900, 'fav_time' => '2025-06-03 00:00:00',
        ]);
        Video::query()->forceCreate([
            'id' => 703, 'link' => 'l', 'title' => 'C', 'intro' => '', 'cover' => 'c',
            'bvid' => 'BVS3', 'pubtime' => '2025-02-01 00:00:00', 'duration' => 200,
            'video_downloaded_num' => 0, 'invalid' => 0, 'frozen' => 0, 'view' => 10, 'fav_time' => '2025-06-02 00:00:00',
        ]);

        // 弹幕数：701=5条, 702=30条, 703=12条
        DanmakuFile::create(['cid' => 70101, 'video_id' => 701, 'count' => 5, 'path' => 'p']);
        DanmakuFile::create(['cid' => 70201, 'video_id' => 702, 'count' => 30, 'path' => 'p']);
        DanmakuFile::create(['cid' => 70301, 'video_id' => 703, 'count' => 12, 'path' => 'p']);
    }

    public function test_sort_by_view_desc(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['sort' => 'view', 'order' => 'desc'], 1, 10);
        $this->assertSame([702, 701, 703], array_column($result['list']->toArray(), 'id'));
    }

    public function test_sort_by_pubtime_asc(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['sort' => 'pubtime', 'order' => 'asc'], 1, 10);
        $this->assertSame([701, 703, 702], array_column($result['list']->toArray(), 'id'));
    }

    public function test_sort_by_favtime_desc(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['sort' => 'favtime'], 1, 10);
        $this->assertSame([702, 703, 701], array_column($result['list']->toArray(), 'id'));
    }

    public function test_sort_by_duration_desc(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['sort' => 'duration'], 1, 10);
        $this->assertSame([702, 703, 701], array_column($result['list']->toArray(), 'id'));
    }

    public function test_sort_by_danmaku_count_desc(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['sort' => 'danmaku'], 1, 10);
        $this->assertSame([702, 703, 701], array_column($result['list']->toArray(), 'id'));
    }

    public function test_invalid_sort_key_is_ignored(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['sort' => 'bad-key; DROP TABLE videos'], 1, 10);
        // 不报错、不排序（自然顺序），且注入字符串被忽略
        $this->assertCount(3, $result['list']);
    }
}
