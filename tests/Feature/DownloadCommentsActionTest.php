<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CommentUser;
use App\Models\Video;
use App\Services\BilibiliService;
use App\Services\CommentImageService;
use App\Services\VideoManager\Actions\Video\DownloadCommentsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * 评论下载批量写入测试（第4项性能优化）。
 */
class DownloadCommentsActionTest extends TestCase
{
    use RefreshDatabase;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->video = Video::query()->create([
            'id' => 555001,
            'link' => 'https://bilibili.com/video/BVCMT',
            'title' => '评论测试',
            'intro' => '',
            'cover' => 'c',
            'bvid' => 'BVCMT',
            'pubtime' => '2025-06-14 12:00:00',
        ]);
    }

    /**
     * 注入 mock：B 站 API 与图片下载均不联网。
     *
     * @param array $comments 格式化后的评论数据（DownloadCommentsAction 的输入形态）
     */
    private function mockDependencies(array $comments): void
    {
        $bili = Mockery::mock(BilibiliService::class);
        $bili->shouldReceive('getVideoInfo')->andReturn(['aid' => 1, 'stat' => ['view' => 1000, 'reply' => count($comments)]]);
        $bili->shouldReceive('getVideoComments')->andReturn($comments);
        $this->app->instance(BilibiliService::class, $bili);

        $img = Mockery::mock(CommentImageService::class);
        $img->shouldReceive('processEmotes')->andReturnUsing(fn ($e) => $e);
        $img->shouldReceive('processPictures')->andReturnUsing(fn ($p) => $p);
        $img->shouldReceive('processAvatar')->andReturnUsing(fn ($a) => $a);
        $this->app->instance(CommentImageService::class, $img);
    }

    private function sampleComments(): array
    {
        return [
            [
                'rpid' => 900001, 'oid' => 1, 'mid' => 111,
                'uname' => '用户一', 'avatar' => '/storage/avatars/u1.webp',
                'content' => '第一条评论', 'like' => 5, 'root' => 0, 'parent' => 0,
                'ctime' => '2025-06-14 12:00:00', 'pictures' => [],
                'emotes' => ['[doge]' => ['webp' => '/storage/emotes/d.webp', 'raw' => '/storage/emotes/d.png']],
                'is_top' => false,
            ],
            [
                'rpid' => 900002, 'oid' => 1, 'mid' => 111,   // 与上一条同用户（应去重为 1 个 comment_user）
                'uname' => '用户一', 'avatar' => '/storage/avatars/u1.webp',
                'content' => '第二条评论', 'like' => 1, 'root' => 0, 'parent' => 0,
                'ctime' => '2025-06-14 12:01:00', 'pictures' => [],
                'emotes' => [], 'is_top' => false,
            ],
            [
                'rpid' => 900003, 'oid' => 1, 'mid' => 222,
                'uname' => '用户二', 'avatar' => '/storage/avatars/u2.webp',
                'content' => '第三条评论', 'like' => 0, 'root' => 900001, 'parent' => 900001,
                'ctime' => '2025-06-14 12:02:00', 'pictures' => [],
                'emotes' => [], 'is_top' => true,
            ],
        ];
    }

    public function test_batch_upsert_inserts_comments_and_users(): void
    {
        $this->mockDependencies($this->sampleComments());

        app(DownloadCommentsAction::class)->execute($this->video, null, 0);

        $this->assertSame(3, Comment::query()->count(), '3 条评论应全部入库');
        $this->assertSame(2, CommentUser::query()->count(), '同用户去重后应为 2 个用户');

        $c1 = Comment::query()->where('rpid', 900001)->first();
        $this->assertSame('', $c1->uname, '归一化后行内 uname 置空');
        $this->assertSame('第一条评论', $c1->content);
        // 表情压缩为 key 列表（emotes 为 array cast，读取时已解码）
        $this->assertIsList($c1->emotes, '全本地化表情应压缩为 key 列表');
        $this->assertSame(['[doge]'], $c1->emotes);

        $user = CommentUser::query()->where('mid', 111)->first();
        $this->assertSame('用户一', $user->uname);
        $this->assertSame('/storage/avatars/u1.webp', $user->avatar);
    }

    public function test_rerun_updates_instead_of_duplicating(): void
    {
        $comments = $this->sampleComments();
        $this->mockDependencies($comments);
        app(DownloadCommentsAction::class)->execute($this->video, null, 0);

        // 同一批 rpid 再次执行、内容有更新
        $comments[0]['content'] = '第一条评论（已编辑）';
        $comments[0]['like'] = 99;
        $this->mockDependencies($comments);
        app(DownloadCommentsAction::class)->execute($this->video, null, 0);

        $this->assertSame(3, Comment::query()->count(), '重复执行不得产生重复评论');
        $this->assertSame(2, CommentUser::query()->count(), '用户数保持不变');

        $c1 = Comment::query()->where('rpid', 900001)->first();
        $this->assertSame('第一条评论（已编辑）', $c1->content, 'upsert 应更新内容');
        $this->assertSame(99, (int) $c1->like);
    }

    public function test_json_fields_roundtrip_and_flags(): void
    {
        // 真实流程中 processEmotes 会把表情注册进 emotes 表（展示还原的查找源）
        \App\Models\Emote::query()->create([
            'url_hash' => 'h1', 'filename' => 'd.webp', 'text' => '[doge]',
        ]);

        $this->mockDependencies($this->sampleComments());
        app(DownloadCommentsAction::class)->execute($this->video, null, 0);

        $top = Comment::query()->where('rpid', 900003)->first();
        $this->assertTrue((bool) $top->is_top, 'is_top 应正确落库');
        $this->assertSame(900001, (int) $top->root);

        // 通过模型 toArray（真实展示路径）可完整还原
        $arr = Comment::query()->where('rpid', 900001)->first()->toArray();
        $this->assertArrayHasKey('[doge]', $arr['emotes'], '展示层应还原表情映射');
        $this->assertArrayHasKey('webp', $arr['emotes']['[doge]']);
        $this->assertSame('用户一', $arr['uname'], '展示层应从 comment_users 还原用户名');
    }
}