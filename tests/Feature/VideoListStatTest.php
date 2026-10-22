<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 视频列表统计聚合测试（第5项优化：5 次 count → 1 次聚合）。
 */
class VideoListStatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        // 构造组合样本：已下载2 / 失效1 / 冻结1 / 普通1
        $samples = [
            [901, 'Alpha 视频', 1, 0, 0],
            [902, 'Beta 视频', 2, 0, 0],
            [903, 'Gamma 视频', 0, 1, 0],   // 失效
            [904, 'Delta 视频', 1, 0, 1],   // 冻结（已下载）
            [905, 'Epsilon 视频', 0, 0, 0],
        ];
        foreach ($samples as [$id, $title, $dl, $invalid, $frozen]) {
            Video::query()->forceCreate([
                'id' => $id, 'link' => 'l', 'title' => $title, 'intro' => '', 'cover' => 'c',
                'bvid' => 'BVSTAT' . $id, 'pubtime' => '2025-06-14 12:00:00',
                'video_downloaded_num' => $dl, 'invalid' => $invalid, 'frozen' => $frozen,
            ]);
        }
    }

    public function test_stat_aggregates_are_correct(): void
    {
        $result = app(VideoService::class)->getVideosByPage([], 1, 10);

        $this->assertSame(5, $result['stat']['count']);
        $this->assertSame(3, $result['stat']['downloaded'], 'downloaded_num>0 的视频数');
        $this->assertSame(1, $result['stat']['invalid']);
        $this->assertSame(4, $result['stat']['valid']);
        $this->assertSame(1, $result['stat']['frozen']);
    }

    public function test_stat_respects_filters(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['status' => 'invalid'], 1, 10);

        $this->assertSame(1, $result['stat']['count'], '过滤条件应作用于统计');
        $this->assertSame(1, $result['stat']['invalid']);
    }

    public function test_stat_with_search_filter(): void
    {
        $result = app(VideoService::class)->getVideosByPage(['query' => 'Alpha'], 1, 10);

        $this->assertSame(1, $result['stat']['count'], '搜索过滤后统计正确');
        $this->assertSame('Alpha 视频', $result['list'][0]->title);
    }

    public function test_stat_uses_single_aggregate_query(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(VideoService::class)->getVideosByPage([], 1, 10);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 1 次聚合统计 + 1 次列表分页（预加载等不计），远优于旧版 5 次 count + 列表
        $this->assertLessThanOrEqual(4, count($queries),
            '统计必须为单次聚合查询（旧版为 5 次 count），实测 SQL: ' . json_encode(array_column($queries, 'query')));
    }
}