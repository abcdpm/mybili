<?php

namespace Tests\Feature;

use App\Jobs\DownloadCommentsJob;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 评论下载调度重构测试（第12项）：错峰投递取代堆积。
 */
class DownloadCommentsSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();

        for ($i = 1; $i <= 5; $i++) {
            Video::query()->forceCreate([
                'id' => 700 + $i, 'link' => 'l', 'title' => '调度测试' . $i, 'intro' => '', 'cover' => 'c',
                'bvid' => 'BVSCH' . $i, 'pubtime' => '2025-06-14 12:00:00',
            ]);
        }
    }

    public function test_polling_dispatch_is_staggered_not_burst(): void
    {
        $this->artisan('app:download-all-comments', [
            '--auto-update' => true,
            '--incremental' => 20,
            '--interval'    => 60,
        ])->assertSuccessful();

        Queue::assertPushed(DownloadCommentsJob::class, 5);

        $delays = [];
        foreach (Queue::pushed(DownloadCommentsJob::class) as $pair) {
            $job = $pair;
            $delays[] = $job->delay instanceof \DateTimeInterface
                ? $job->delay->getTimestamp()
                : (int) $job->delay;
        }

        $this->assertSame(5, count(array_unique($delays)), '5 个任务应有 5 个不同投递时刻（错峰而非一次性堆积）');
        $this->assertGreaterThan(0, max($delays) - min($delays), '任务应散布在时间轴上');
        sort($delays);
        $this->assertSame($delays[0] + 60, $delays[1], '间隔应为 --interval=60 秒');
    }

    public function test_jobs_are_dispatched_without_in_job_sleep(): void
    {
        $this->artisan('app:download-all-comments', [
            '--auto-update' => true,
            '--incremental' => 20,
            '--interval'    => 10,
        ])->assertSuccessful();

        foreach (Queue::pushed(DownloadCommentsJob::class) as $pair) {
            $job = $pair;
            $this->assertSame(0, $job->sleep, '错峰投递后任务内不再 sleep 阻塞 worker');
        }
    }
}