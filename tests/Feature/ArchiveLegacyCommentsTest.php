<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 主库瘦身·旧表归档（app:archive-legacy-comments）测试。
 *
 * 覆盖：拆分未激活拒绝、仅归档不删除、显式删除+恢复往返、
 * 与 split --rollback 的防呆联锁。主库为真实文件以覆盖生产 ATTACH 路径。
 */
class ArchiveLegacyCommentsTest extends TestCase
{
    private string $mainPath;

    private string $commentsPath;

    private string $markerPath;

    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $uniq = uniqid('', true);
        $this->mainPath = sys_get_temp_dir()."/arch-main-{$uniq}.sqlite";
        $this->commentsPath = sys_get_temp_dir()."/arch-comments-{$uniq}.sqlite";
        $this->markerPath = sys_get_temp_dir()."/arch-marker-{$uniq}.json";
        $this->archivePath = sys_get_temp_dir()."/arch-archive-{$uniq}.sqlite";
        touch($this->mainPath);

        config([
            'database.connections.sqlite.database' => $this->mainPath,
            'database.connections.sqlite_comments.database' => $this->commentsPath,
            'database.comments_split_marker' => $this->markerPath,
        ]);
        DB::purge('sqlite');
        DB::purge('sqlite_comments');
        comments_db_split_flush();

        $this->artisan('migrate', ['--database' => 'sqlite']);
        $this->seedCommentsData();
    }

    protected function tearDown(): void
    {
        comments_db_split_flush();
        foreach ([$this->mainPath, $this->commentsPath, $this->markerPath, $this->archivePath] as $f) {
            @unlink($f);
            @unlink($f.'-wal');
            @unlink($f.'-shm');
        }
        parent::tearDown();
    }

    private function seedCommentsData(): void
    {
        foreach ([1, 2] as $i) {
            \App\Models\Video::query()->forceCreate([
                'id' => $i, 'link' => 'l', 'title' => "视频{$i}", 'intro' => '', 'cover' => 'c',
                'bvid' => 'BVA'.$i, 'pubtime' => '2025-06-14 12:00:00',
            ]);
        }
        DB::table('comments')->insert([
            ['video_id' => 1, 'rpid' => 11, 'oid' => 1, 'mid' => 101, 'uname' => 'u1', 'avatar' => 'a', 'content' => '评论一', 'like' => 1, 'root' => 0, 'parent' => 0, 'ctime' => now(), 'created_at' => now(), 'updated_at' => now(), 'is_top' => 0],
            ['video_id' => 2, 'rpid' => 21, 'oid' => 2, 'mid' => 102, 'uname' => 'u2', 'avatar' => 'b', 'content' => '评论二', 'like' => 0, 'root' => 0, 'parent' => 0, 'ctime' => now(), 'created_at' => now(), 'updated_at' => now(), 'is_top' => 0],
        ]);
        DB::table('comment_users')->insert([
            ['mid' => 101, 'uname' => 'u1', 'avatar' => 'a', 'updated_at' => now()],
            ['mid' => 102, 'uname' => 'u2', 'avatar' => 'b', 'updated_at' => now()],
        ]);
        DB::table('emotes')->insert([
            ['url_hash' => 'h1', 'filename' => 'x.gif', 'text' => '[doge]', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** 先跑完整拆分（产生独立库 + 标记），归档工具的前置状态 */
    private function activateSplit(): void
    {
        $this->artisan('app:split-comments-db', ['--execute' => true])->assertExitCode(0);
    }

    public function test_refuses_when_split_inactive(): void
    {
        $this->artisan('app:archive-legacy-comments', ['--execute' => true, '--archive' => $this->archivePath])
            ->assertExitCode(1);
        $this->assertFileDoesNotExist($this->archivePath, '拆分未激活时不得归档');
    }

    public function test_archive_only_keeps_legacy_tables(): void
    {
        $this->activateSplit();

        $this->artisan('app:archive-legacy-comments', ['--execute' => true, '--archive' => $this->archivePath])
            ->assertExitCode(0);

        $this->assertFileExists($this->archivePath);
        // 仅归档：主库旧表原样保留
        $this->assertSame(2, DB::table('comments')->count());
        $this->assertSame(2, DB::table('comment_users')->count());
        $this->assertSame(1, DB::table('emotes')->count());
    }

    public function test_drop_then_restore_roundtrip_and_rollback_interlock(): void
    {
        $this->activateSplit();

        // 归档 + 显式删除 + 回收
        $this->artisan('app:archive-legacy-comments', [
            '--execute' => true, '--drop' => true, '--archive' => $this->archivePath,
        ])->assertExitCode(0);

        // 旧表已从主库删除（独立库为权威数据源，读写不受影响）
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('comments'));
        $this->assertSame(2, \App\Models\Comment::count(), '拆分激活期间业务读写走独立库，不受旧表删除影响');

        // 防呆联锁：旧表缺失时 split --rollback 被阻止
        $this->artisan('app:split-comments-db', ['--rollback' => true])->assertExitCode(1);
        $this->assertFileExists($this->markerPath, '被阻止的回退不得移除标记');

        // 从归档恢复旧表
        $this->artisan('app:archive-legacy-comments', ['--restore' => true, '--from' => $this->archivePath])
            ->assertExitCode(0);
        $this->assertSame(2, DB::table('comments')->count());
        $this->assertSame(2, DB::table('comment_users')->count());
        $this->assertSame(1, DB::table('emotes')->count());

        // 恢复后可正常回退
        $this->artisan('app:split-comments-db', ['--rollback' => true])->assertExitCode(0);
        $this->assertFileDoesNotExist($this->markerPath);
        $this->assertSame(2, \App\Models\Comment::count(), '回退后读主库旧表');
    }

    public function test_status_is_readonly(): void
    {
        $this->activateSplit();
        $this->artisan('app:archive-legacy-comments', ['--status' => true])->assertExitCode(0);
        $this->assertFileDoesNotExist($this->archivePath);
        $this->assertSame(2, DB::table('comments')->count());
    }
}
