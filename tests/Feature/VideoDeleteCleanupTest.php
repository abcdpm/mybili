<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Danmaku;
use App\Models\DanmakuFile;
use App\Models\Video;
use App\Models\VideoPart;
use App\Services\Danmaku\DanmakuFileStore;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 视频永久删除的关联数据清理测试。
 *
 * 覆盖旧版缺陷：永久删除曾是「软删除 + 不清理评论」，导致弹幕全表扫描与孤儿数据。
 */
class VideoDeleteCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->tmpPath = sys_get_temp_dir() . '/mybili_danmaku_del_' . uniqid();
        config(['danmaku.path' => $this->tmpPath]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpPath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpPath);
        parent::tearDown();
    }

    private function makeVideo(int $id): Video
    {
        return Video::query()->create([
            'id'     => $id,
            'link'   => 'https://bilibili.com/video/BVTEST' . $id,
            'title'  => '测试视频' . $id,
            'intro'  => 'intro',
            'cover'  => 'https://example.com/cover.jpg',
            'bvid'   => 'BVTEST' . $id,
            'pubtime' => '2025-06-14 12:00:00',
        ]);
    }

    private function seedRelatedData(int $videoId, int $cid): void
    {
        DB::table('video_parts')->insert([
            'video_id' => $videoId, 'cid' => $cid, 'page' => 1,
            'from' => 'vupload', 'part' => 'P1', 'duration' => 60,
            'vid' => '', 'weblink' => '',
        ]);
        Comment::query()->create([
            'video_id' => $videoId, 'rpid' => 9000000 + $videoId, 'oid' => 1,
            'mid' => 1, 'uname' => 'u', 'avatar' => '/storage/avatars/a.webp',
            'content' => '测试评论', 'like' => 0, 'root' => 0, 'parent' => 0,
            'ctime' => '2025-06-14 12:00:00',
        ]);
        // 先写弹幕文件（save 的自迁移会清理该 cid 遗留行，因此遗留行必须在 save 之后插入）
        app(DanmakuFileStore::class)->save($cid, [
            ['id' => 8000000 + $videoId, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '文件弹幕'],
        ], $videoId);
        Danmaku::query()->insert([
            'id' => 7000000 + $videoId, 'video_id' => $videoId, 'cid' => $cid,
            'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '弹幕',
            'created_at' => now(),
        ]);
        DB::table('favorite_list_videos')->insert(['favorite_list_id' => 1, 'video_id' => $videoId]);
        DB::table('subscription_videos')->insert([
            'subscription_id' => 1,
            'video_id' => $videoId,
            'bvid' => 'BVTEST' . $videoId,
        ]);
    }

    public function test_permanent_delete_purges_all_related_data(): void
    {
        $video = $this->makeVideo(900001);
        $this->seedRelatedData(900001, 101);

        app(VideoService::class)->deleteVideos([900001], ['permanent' => true]);

        $this->assertNull(Video::withTrashed()->find(900001), '永久删除后视频记录应彻底移除（含软删除行）');
        $this->assertSame(0, Comment::query()->where('video_id', 900001)->count(), '评论应被清理');
        $this->assertSame(0, Danmaku::query()->where('video_id', 900001)->count(), '弹幕数据库行应被清理');
        $this->assertSame(0, DanmakuFile::query()->where('video_id', 900001)->count(), '弹幕文件索引应被清理');
        $this->assertSame(0, VideoPart::query()->where('video_id', 900001)->count(), '分P应被清理');
        $this->assertSame(0, DB::table('favorite_list_videos')->where('video_id', 900001)->count(), '收藏夹关联应被清理');
        $this->assertSame(0, DB::table('subscription_videos')->where('video_id', 900001)->count(), '订阅关联应被清理');

        $store = app(DanmakuFileStore::class);
        $this->assertFalse($store->exists(101), '弹幕文件应被删除');
    }

    public function test_non_permanent_delete_keeps_records(): void
    {
        $this->makeVideo(900002);
        $this->seedRelatedData(900002, 102);

        app(VideoService::class)->deleteVideos([900002], ['permanent' => false]);

        $this->assertNotNull(Video::find(900002), '非永久删除应保留视频记录');
        $this->assertSame(1, Comment::query()->where('video_id', 900002)->count(), '非永久删除不清理评论');
    }

    public function test_delete_does_not_touch_other_videos(): void
    {
        $this->makeVideo(900003);
        $this->makeVideo(900004);
        $this->seedRelatedData(900003, 103);
        $this->seedRelatedData(900004, 104);

        app(VideoService::class)->deleteVideos([900003], ['permanent' => true]);

        $this->assertNotNull(Video::find(900004), '其他视频不应受影响');
        $this->assertSame(1, Comment::query()->where('video_id', 900004)->count());
        $this->assertSame(1, Danmaku::query()->where('video_id', 900004)->count());
        $this->assertTrue(app(DanmakuFileStore::class)->exists(104));
    }
}