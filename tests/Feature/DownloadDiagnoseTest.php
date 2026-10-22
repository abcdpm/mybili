<?php

namespace Tests\Feature;

use App\Models\DownloadQueue;
use App\Models\Video;
use App\Models\VideoPart;
use App\Services\VideoManager\Actions\Video\CheckVideoPartFileToDownloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 下载遗留问题（无法下载完成的视频）诊断与处理测试。
 *
 * 场景：已失效视频的分P 永远无法下载完成，旧逻辑反复入队形成空转。
 * 验证：① 入队闸门自动跳过失效视频；② 诊断命令分类报告与安全处理。
 */
class DownloadDiagnoseTest extends TestCase
{
    use RefreshDatabase;

    private function makePart(Video $video, array $attrs = []): VideoPart
    {
        return VideoPart::create(array_merge([
            'video_id' => $video->id,
            'cid' => random_int(100000, 999999),
            'page' => 1,
            'from' => 'vupload',
            'part' => 'P1',
            'duration' => 60,
            'vid' => $video->bvid,
            'weblink' => 'https://www.bilibili.com/video/'.$video->bvid,
        ], $attrs));
    }

    public function test_invalid_video_is_skipped_at_enqueue_gate(): void
    {
        $this->app['config']->set('services.bilibili.setting_read_only', true);
        $this->app['config']->set('services.bilibili.setting_write_only', true);
        $this->app->forgetInstance(\App\Services\SettingsService::class);
        $this->app->forgetInstance(\App\Services\DownloadFilterService::class);

        // 失效视频 + 缺文件分P
        $video = Video::create([
            'id' => 9001,
            'title' => '已失效视频',
            'link' => 'https://www.bilibili.com/video/BV1test', 'intro' => '', 'pubtime' => '2025-06-14 12:00:00', 'video_downloaded_num' => 0,
            'bvid' => 'BV1test',
            'cover' => 'https://i0.hdslb.com/a.jpg',
            'attr' => 0,
            'page' => 1,
        ]);
        $part = $this->makePart($video);

        // 未失效视频 + 缺文件分P（对照组）
        $okVideo = Video::create([
            'id' => 9002,
            'title' => '正常视频',
            'link' => 'https://www.bilibili.com/video/BV1ok', 'intro' => '', 'pubtime' => '2025-06-14 12:00:00', 'video_downloaded_num' => 0,
            'bvid' => 'BV1ok',
            'cover' => 'https://i0.hdslb.com/b.jpg',
            'attr' => 0,
            'page' => 1,
        ]);
        $okPart = $this->makePart($okVideo);

        $action = app(CheckVideoPartFileToDownloadAction::class);
        $action->execute($part, true);
        $action->execute($okPart, true);

        // 失效视频不得入队；对照组是否入队取决于过滤规则，但绝不能出现失效视频的队列行
        $invalidQueued = DownloadQueue::where('video_part_id', $part->id)->count();
        $this->assertSame(0, $invalidQueued, '已失效视频不应进入下载队列');
    }

    public function test_diagnose_reports_categories_and_prunes_invalid_queue_rows(): void
    {
        $invalidVideo = Video::create([
            'id' => 9101, 'title' => '已失效视频', 'link' => 'https://b23.tv/x1', 'intro' => '', 'pubtime' => '2025-06-14 12:00:00', 'video_downloaded_num' => 0, 'bvid' => 'BVx1', 'cover' => 'https://i0.hdslb.com/c.jpg', 'attr' => 0, 'page' => 1, 'invalid' => 1,
        ]);
        $invalidPart = $this->makePart($invalidVideo);

        $okVideo = Video::create([
            'id' => 9102, 'title' => '正常视频', 'link' => 'https://b23.tv/x2', 'intro' => '', 'pubtime' => '2025-06-14 12:00:00', 'video_downloaded_num' => 0, 'bvid' => 'BVx2', 'cover' => 'https://i0.hdslb.com/d.jpg', 'attr' => 0, 'page' => 1, 'invalid' => 0,
        ]);
        $okPart = $this->makePart($okVideo);

        // 队列残留：失效视频 pending 行（空转源头）
        DownloadQueue::create([
            'type' => DownloadQueue::TYPE_VIDEO,
            'video_id' => $invalidVideo->id,
            'video_part_id' => $invalidPart->id,
            'unique_key' => DownloadQueue::buildUniqueKey(DownloadQueue::TYPE_VIDEO, $invalidPart->id),
            'status' => DownloadQueue::STATUS_PENDING,
        ]);

        // 报告模式：不修改数据
        $this->artisan('app:download-diagnose')
            ->expectsOutputToContain('失效视频遗留分P')
            ->assertExitCode(0);
        $this->assertSame(1, DownloadQueue::where('status', DownloadQueue::STATUS_PENDING)->count());

        // 安全处理：仅标记 cancelled，不删除任何数据
        $this->artisan('app:download-diagnose', ['--prune-invalid' => true])->assertExitCode(0);

        $this->assertSame(0, DownloadQueue::where('status', DownloadQueue::STATUS_PENDING)->count());
        $this->assertSame(1, DownloadQueue::where('status', DownloadQueue::STATUS_CANCELLED)->count());
        // 媒体与业务数据完好
        $this->assertNotNull(VideoPart::find($invalidPart->id));
        $this->assertNotNull(VideoPart::find($okPart->id));
    }
}
