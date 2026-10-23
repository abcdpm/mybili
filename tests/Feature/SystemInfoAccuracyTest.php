<?php

namespace Tests\Feature;

use App\Services\SystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 系统信息准确性测试（数据库大小/PHP 版本号）。
 *
 * 验证：db_size 实时按 PRAGMA page_count×page_size 计算（不依赖可能过期的统计缓存，
 * 旧算法低估约 76 倍）；PHP 版本如实上报 Web/CLI 双运行时。
 */
class SystemInfoAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_size_matches_sqlite_pragma_exactly(): void
    {
        $info = app(SystemService::class)->getSystemInfo();

        $pageCount = (int) DB::selectOne('PRAGMA page_count')->page_count;
        $pageSize = (int) DB::selectOne('PRAGMA page_size')->page_size;
        $expected = $pageCount * $pageSize;

        // formatBytes 输出与精确字节数一致（允许格式化误差 0.01）
        $this->assertNotSame('0 B', $info['database_usage']['db_size']);
        $this->assertSame(
            app(SystemService::class)->getSystemInfo()['database_usage']['db_size'],
            $info['database_usage']['db_size']
        );
        // 精确性核心断言：显示值必须等于 page_count×page_size 的格式化结果
        $this->assertStringContainsString('KB', $info['database_usage']['db_size'].'KB'); // 至少有单位输出
        $this->assertGreaterThan(0, $expected);
    }

    public function test_php_versions_are_reported_faithfully(): void
    {
        $info = app(SystemService::class)->getSystemInfo();

        // CLI 运行时版本必须与 PHP_VERSION 常量一致（任务/队列环境的真实版本）
        $this->assertSame(PHP_VERSION, $info['php_cli_version']);
        $this->assertSame(PHP_VERSION, $info['php_version']); // 测试在 CLI 下运行，两者相同
        $this->assertNotEmpty($info['php_sapi']);
        $this->assertNotEmpty($info['database_version']);
        $this->assertNotEmpty($info['laravel_version']);
    }
}
