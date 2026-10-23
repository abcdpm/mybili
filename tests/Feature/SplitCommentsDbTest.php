<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CommentUser;
use App\Models\Emote;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 评论库拆分（批F·架构）测试。
 *
 * 覆盖：复制+校验+切换全链路（ATTACH 快路径与 PHP 分批路径）、
 * 拆分后读写路由到独立库、回滚后回到主库、跨库查询兼容。
 * 不使用 RefreshDatabase（主库需为真实文件以覆盖生产 ATTACH 路径），手动迁移与清理。
 */
class SplitCommentsDbTest extends TestCase
{
    private string $mainPath;

    private string $commentsPath;

    private string $markerPath;

    /** 当前用例是否使用真实文件主库（ATTACH 路径） */
    private bool $fileBased = true;

    protected function setUp(): void
    {
        parent::setUp();

        $uniq = uniqid('', true);
        $this->mainPath = sys_get_temp_dir()."/split-main-{$uniq}.sqlite";
        $this->commentsPath = sys_get_temp_dir()."/split-comments-{$uniq}.sqlite";
        $this->markerPath = sys_get_temp_dir()."/split-marker-{$uniq}.json";

        if ($this->fileBased) {
            touch($this->mainPath);
            config([
                'database.connections.sqlite.database' => $this->mainPath,
                'database.connections.sqlite_comments.database' => $this->commentsPath,
                'database.comments_split_marker' => $this->markerPath,
            ]);
            DB::purge('sqlite');
        } else {
            config([
                'database.connections.sqlite_comments.database' => $this->commentsPath,
                'database.comments_split_marker' => $this->markerPath,
            ]);
        }
        DB::purge('sqlite_comments');
        comments_db_split_flush();

        $this->artisan('migrate', ['--database' => $this->fileBased ? 'sqlite' : config('database.default')]);

        $this->seedCommentsData();
    }

    protected function tearDown(): void
    {
        comments_db_split_flush();
        foreach ([$this->mainPath, $this->commentsPath, $this->markerPath] as $f) {
            @unlink($f);
            @unlink($f.'-wal');
            @unlink($f.'-shm');
        }
        parent::tearDown();
    }

    private function seedCommentsData(): void
    {
        // comments 外键指向 videos，先建父行（用模型插入以带出默认属性）
        foreach (['第一评论视频', '第二评论视频', '拆分后新增视频'] as $i => $title) {
            \App\Models\Video::query()->forceCreate([
                'id' => $i + 1, 'link' => 'l', 'title' => $title, 'intro' => '', 'cover' => 'c',
                'bvid' => 'BVS'.($i + 1), 'pubtime' => '2025-06-14 12:00:00',
            ]);
        }

        DB::table('comments')->insert([
            ['video_id' => 1, 'rpid' => 11, 'oid' => 1, 'mid' => 101, 'uname' => 'u1', 'avatar' => 'a', 'content' => '第一条评论', 'like' => 1, 'root' => 0, 'parent' => 0, 'ctime' => now(), 'created_at' => now(), 'updated_at' => now(), 'is_top' => 0],
            ['video_id' => 1, 'rpid' => 12, 'oid' => 1, 'mid' => 102, 'uname' => 'u2', 'avatar' => 'b', 'content' => '第二条评论', 'like' => 0, 'root' => 0, 'parent' => 0, 'ctime' => now(), 'created_at' => now(), 'updated_at' => now(), 'is_top' => 0],
            ['video_id' => 2, 'rpid' => 21, 'oid' => 2, 'mid' => 101, 'uname' => 'u1', 'avatar' => 'a', 'content' => '第三条评论', 'like' => 3, 'root' => 0, 'parent' => 0, 'ctime' => now(), 'created_at' => now(), 'updated_at' => now(), 'is_top' => 0],
        ]);
        DB::table('comment_users')->insert([
            ['mid' => 101, 'uname' => 'u1', 'avatar' => 'a', 'updated_at' => now()],
            ['mid' => 102, 'uname' => 'u2', 'avatar' => 'b', 'updated_at' => now()],
        ]);
        DB::table('emotes')->insert([
            ['url_hash' => 'h1', 'filename' => 'x.gif', 'text' => '[doge]', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_split_copy_verify_switch_with_attach_fast_path(): void
    {
        $this->fileBased = true;

        $this->artisan('app:split-comments-db', ['--execute' => true])
            ->assertExitCode(0);

        // 标记写入 = 已切换
        $this->assertFileExists($this->markerPath);

        // 读写走独立库：行数一致
        $this->assertSame(3, Comment::count());
        $this->assertSame(2, CommentUser::count());
        $this->assertSame(1, Emote::count());

        // 新写入应落在独立库（主库旧表行数不变）
        $mainCommentsBefore = DB::table('comments')->count();
        Comment::create([
            'video_id' => 3, 'rpid' => 31, 'oid' => 3, 'mid' => 103, 'uname' => 'u3', 'avatar' => 'c',
            'content' => '拆分后新增', 'like' => 0, 'root' => 0, 'parent' => 0, 'ctime' => now(), 'is_top' => 0,
        ]);
        $this->assertSame($mainCommentsBefore, DB::table('comments')->count(), '拆分后主库旧表只读，不应新增');
        $this->assertSame(4, Comment::count(), '拆分后新写入应进入独立库');

        // 回滚：标记移除，读写立即回主库旧表
        $this->artisan('app:split-comments-db', ['--rollback' => true])->assertExitCode(0);
        $this->assertFileDoesNotExist($this->markerPath);
        comments_db_split_flush();
        $this->assertSame(3, Comment::count(), '回滚后应回到主库旧表（3 行）');
    }

    public function test_split_with_php_chunked_copy_when_source_is_memory(): void
    {
        // 源库为 :memory:（默认测试库）→ 命令走 PHP 分批复制路径
        $this->fileBased = false;

        $this->artisan('app:split-comments-db', ['--execute' => true, '--chunk' => 1])
            ->assertExitCode(0);

        $this->assertFileExists($this->markerPath);
        $this->assertSame(3, Comment::count());
        $this->assertSame(2, CommentUser::count());
        $this->assertSame(1, Emote::count());
        // 内容级校验（防行数相同内容错位）
        $this->assertSame('第一条评论', Comment::where('rpid', 11)->first()->content);
    }

    public function test_status_reports_without_switching(): void
    {
        $this->artisan('app:split-comments-db', ['--status' => true])->assertExitCode(0);
        $this->assertFileDoesNotExist($this->markerPath, '--status 只读，不得切换');
    }

    public function test_rollback_without_split_is_noop(): void
    {
        $this->artisan('app:split-comments-db', ['--rollback' => true])->assertExitCode(0);
        $this->assertFileDoesNotExist($this->markerPath);
    }

    public function test_emote_model_routes_to_comments_db_after_split(): void
    {
        $this->artisan('app:split-comments-db', ['--execute' => true])->assertExitCode(0);

        // Emote 解析路径（表情包）应命中独立库
        $this->assertSame('x.gif', Emote::where('text', '[doge]')->first()->filename);

        Emote::create(['text' => '[笑哭]', 'filename' => 'y.webp', 'url_hash' => 'h2']);
        $this->assertSame(2, Emote::count());
        $this->assertSame(1, DB::table('emotes')->count(), '拆分后主库 emotes 旧表只读');
    }
}
