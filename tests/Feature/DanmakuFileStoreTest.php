<?php

namespace Tests\Feature;

use App\Models\Danmaku;
use App\Models\DanmakuFile;
use App\Services\Danmaku\DanmakuFileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 弹幕文件化存储测试：写入/去重/合并/遗留行自迁移/删除。
 */
class DanmakuFileStoreTest extends TestCase
{
    use RefreshDatabase;

    private DanmakuFileStore $store;
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->tmpPath = sys_get_temp_dir() . '/mybili_danmaku_test_' . uniqid();
        config(['danmaku.path' => $this->tmpPath]);
        $this->store = app(DanmakuFileStore::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpPath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpPath);
        parent::tearDown();
    }

    private function legacyRow(int $id, int $cid, string $content): void
    {
        Danmaku::query()->insert([
            'id' => $id,
            'video_id' => 1,
            'cid' => $cid,
            'progress' => 1000 * $id,
            'mode' => 1,
            'color' => 16777215,
            'content' => $content,
            'created_at' => now(),
        ]);
    }

    public function test_save_writes_file_and_index(): void
    {
        $count = $this->store->save(10001, [
            ['id' => 11, 'progress' => 100, 'mode' => 1, 'color' => 255, 'content' => '第一条'],
            ['id' => 12, 'progress' => 200, 'mode' => 1, 'color' => 255, 'content' => '第二条'],
        ], 77);

        $this->assertSame(2, $count);
        $this->assertTrue($this->store->exists(10001));
        $this->assertSame(2, DanmakuFile::query()->where('cid', 10001)->value('count'));
        $this->assertSame(77, DanmakuFile::query()->where('cid', 10001)->value('video_id'));

        $items = $this->store->read(10001);
        $this->assertCount(2, $items);
        $this->assertSame('第一条', $items[0]['content']);
    }

    public function test_save_dedupes_by_id(): void
    {
        $this->store->save(10002, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => 'A'],
        ]);
        $count = $this->store->save(10002, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => 'A重复'],
            ['id' => 2, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => 'B'],
        ]);

        $this->assertSame(2, $count, '相同弹幕 id 应去重');
        $this->assertSame(2, DanmakuFile::query()->where('cid', 10002)->value('count'));
    }

    public function test_save_migrates_legacy_rows_and_purges_them(): void
    {
        $this->legacyRow(501, 10003, '老数据1');
        $this->legacyRow(502, 10003, '老数据2');

        $count = $this->store->save(10003, [
            ['id' => 503, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '新数据'],
        ], 88);

        $this->assertSame(3, $count, '文件应包含遗留行与新数据');
        $this->assertSame(0, Danmaku::query()->where('cid', 10003)->count(), '写入成功后遗留行应被安全清理（自迁移）');

        $merged = $this->store->merged(10003);
        $this->assertCount(3, $merged);
    }

    public function test_merged_falls_back_to_legacy_rows(): void
    {
        $this->legacyRow(601, 10004, '仅存在于数据库');

        $merged = $this->store->merged(10004);
        $this->assertCount(1, $merged);
        $this->assertSame('仅存在于数据库', $merged[0]['content']);
    }

    public function test_merged_dedupes_across_file_and_rows(): void
    {
        // 文件中已有 id=701，数据库遗留同 id 行 → 合并后仅 1 条
        $this->store->save(10005, [
            ['id' => 701, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '文件版'],
        ]);
        // 手工插入同 id 遗留行（模拟迁移中间态）
        $this->legacyRow(701, 10005, '数据库版');

        $this->assertCount(1, $this->store->merged(10005));
    }

    public function test_delete_removes_file_index_and_rows(): void
    {
        $this->store->save(10006, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => 'x'],
        ], 66);
        $this->legacyRow(999, 10006, '遗留');

        $this->store->delete(10006);

        $this->assertFalse($this->store->exists(10006));
        $this->assertSame(0, DanmakuFile::query()->where('cid', 10006)->count());
        $this->assertSame(0, Danmaku::query()->where('cid', 10006)->count());
    }

    public function test_empty_content_filtered(): void
    {
        $count = $this->store->save(10007, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => ''],
            ['id' => 2, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '有效'],
        ]);

        $this->assertSame(1, $count);
    }

    public function test_dirty_utf8_content_is_scrubbed_not_lost(): void
    {
        // B 站老弹幕偶见非法 UTF-8 字节，此前会导致 json_encode 失败（迁移报"弹幕序列化失败"）
        $dirty = "正常弹幕" . chr(0xB1) . chr(0x31) . "尾部";

        $count = $this->store->save(10008, [
            ['id' => 1, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => $dirty],
            ['id' => 2, 'progress' => 0, 'mode' => 1, 'color' => 255, 'content' => '普通弹幕'],
        ]);

        $this->assertSame(2, $count, '非法 UTF-8 弹幕不应导致保存失败');

        $items = $this->store->read(10008);
        $this->assertCount(2, $items);
        $this->assertStringContainsString('正常弹幕', $items[0]['content'], '可显示部分应保留');
        $this->assertStringContainsString('尾部', $items[0]['content']);
        $this->assertStringContainsString('普通弹幕', $items[1]['content']);

        // 文件内容必须是合法 UTF-8 JSON（读取回来的 content 不含非法字节）
        $this->assertSame(1, preg_match('//u', $items[0]['content']));
    }
}