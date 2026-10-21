<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CommentUser;
use App\Models\Emote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 评论归一化测试：用户信息（comment_users）与表情包（key 列表 + EmoteResolver）
 * 的新旧双格式兼容，输出结构对前端保持完全一致。
 */
class CommentNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        // comments.video_id 存在外键约束，先创建父视频
        \App\Models\Video::query()->create([
            'id' => 1,
            'link' => 'https://bilibili.com/video/BV1',
            'title' => '评论测试视频',
            'intro' => '',
            'cover' => 'https://example.com/c.jpg',
            'bvid' => 'BV1',
            'pubtime' => '2025-06-14 12:00:00',
        ]);
    }

    private function makeComment(array $attrs): Comment
    {
        return Comment::query()->create(array_merge([
            'video_id' => 1,
            'rpid' => 1000 + Comment::count(),
            'oid' => 1,
            'mid' => 1,
            'uname' => '',
            'avatar' => '',
            'content' => '内容',
            'like' => 0,
            'root' => 0,
            'parent' => 0,
            'ctime' => '2025-06-14 12:00:00',
        ], $attrs));
    }

    public function test_legacy_comment_output_unchanged(): void
    {
        $comment = $this->makeComment([
            'uname' => '老用户',
            'avatar' => '/storage/avatars/old.webp',
            'emotes' => [
                '[doge]' => ['webp' => '/storage/emotes/d.webp', 'raw' => '/storage/emotes/d.png'],
            ],
        ]);

        $array = $comment->toArray();

        $this->assertSame('老用户', $array['uname'], '旧数据行内用户名应原样输出');
        $this->assertSame(asset('/storage/avatars/old.webp'), $array['avatar']);
        $this->assertArrayHasKey('[doge]', $array['emotes']);
        $this->assertSame(asset('/storage/emotes/d.webp'), $array['emotes']['[doge]']['webp']);
        $this->assertSame(asset('/storage/emotes/d.png'), $array['emotes']['[doge]']['raw']);
    }

    public function test_normalized_comment_resolves_user_and_emotes(): void
    {
        // 归一化用户信息
        CommentUser::query()->create(['mid' => 233, 'uname' => '新用户', 'avatar' => '/storage/avatars/new.webp']);

        // 本地表情包索引
        Emote::query()->create(['url_hash' => 'hash1', 'filename' => 'doge.webp', 'text' => '[doge]']);

        // 新版评论：行内 uname/avatar 为空，表情包为 key 列表
        $comment = $this->makeComment([
            'mid' => 233,
            'emotes' => ['[doge]'],
        ]);

        $array = $comment->toArray();

        $this->assertSame('新用户', $array['uname'], '应回退到 comment_users 的用户名');
        $this->assertSame(asset('/storage/avatars/new.webp'), $array['avatar']);
        $this->assertArrayHasKey('[doge]', $array['emotes'], 'key 列表应还原为完整表情映射');
        $this->assertSame(asset('/storage/emotes/doge.webp'), $array['emotes']['[doge]']['webp']);
    }

    public function test_unresolved_emote_keys_are_skipped(): void
    {
        $comment = $this->makeComment([
            'mid' => 244,
            'emotes' => ['[不存在的表情]'],
        ]);

        $array = $comment->toArray();
        $this->assertSame([], $array['emotes'], '解析不到的表情应跳过，不输出半成品结构');
    }

    public function test_missing_comment_user_falls_back_to_empty(): void
    {
        $comment = $this->makeComment(['mid' => 999]);

        $array = $comment->toArray();
        $this->assertSame('', $array['uname']);
        $this->assertSame('', $array['avatar']);
    }
}