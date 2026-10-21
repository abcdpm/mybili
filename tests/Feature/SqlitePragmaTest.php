<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQLite 并发优化（PragmaConnector）测试：
 * 每条连接自动启用 WAL / busy_timeout，覆盖旧版仅启动连接生效的缺陷。
 */
class SqlitePragmaTest extends TestCase
{
    public function test_wal_and_busy_timeout_applied_on_connect(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mybili_pragma_') . '.sqlite';
        touch($tmp);

        config(['database.connections.sqlite.database' => $tmp]);
        DB::purge('sqlite');

        $mode = DB::select('PRAGMA journal_mode');
        $this->assertSame('wal', strtolower((string) ($mode[0]->journal_mode ?? '')), 'journal_mode 应为 WAL');

        $busy = DB::select('PRAGMA busy_timeout');
        $this->assertSame(5000, (int) ($busy[0]->timeout ?? 0), 'busy_timeout 应为 5000ms');

        DB::purge('sqlite');
        @unlink($tmp);
    }

    public function test_reconnect_applies_pragmas_to_every_connection(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mybili_pragma2_') . '.sqlite';
        touch($tmp);
        config(['database.connections.sqlite.database' => $tmp]);

        DB::purge('sqlite');
        DB::select('SELECT 1');
        DB::purge('sqlite'); // 模拟新连接（如队列 Worker / Octane）

        $busy = DB::select('PRAGMA busy_timeout');
        $this->assertSame(5000, (int) ($busy[0]->timeout ?? 0), '新连接同样应获得 busy_timeout 优化');

        DB::purge('sqlite');
        @unlink($tmp);
    }
}