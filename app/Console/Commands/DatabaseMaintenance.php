<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * SQLite 数据库维护命令。
 *
 * - --init    初始化 PRAGMA（WAL 等），供 entrypoint 在启动时调用；
 *             由 PragmaConnector 处理的连接会自动获得，此选项保证
 *             即使连接器未生效（如直连工具）也能完成初始化。
 * - --analyze 更新查询优化器统计信息（轻量，可每日执行）。
 * - --vacuum  执行 VACUUM 回收磁盘空间 + ANALYZE（较重，建议每周低峰执行）。
 *
 * 兼容性：非 SQLite 数据库自动跳过，不会报错。
 */
class DatabaseMaintenance extends Command
{
    protected $signature = 'app:database-maintenance
        {--init : 初始化 SQLite PRAGMA（WAL/busy_timeout 等）}
        {--analyze : 更新查询优化器统计信息（PRAGMA optimize）}
        {--vacuum : 执行 VACUUM 回收空间并 ANALYZE（耗时较长，会短暂阻塞写入）}';

    protected $description = 'SQLite 数据库维护：PRAGMA 初始化 / 统计信息更新 / VACUUM 空间回收';

    public function handle(): int
    {
        if (! $this->isSqlite()) {
            $this->info('当前不是 SQLite 数据库，跳过维护。');

            return self::SUCCESS;
        }

        if (! $this->option('init') && ! $this->option('analyze') && ! $this->option('vacuum')) {
            $this->warn('请至少指定一个操作：--init / --analyze / --vacuum');

            return self::FAILURE;
        }

        if ($this->option('init')) {
            $this->runInit();
        }

        if ($this->option('analyze')) {
            $this->runAnalyze();
        }

        if ($this->option('vacuum')) {
            $this->runVacuum();
        }

        return self::SUCCESS;
    }

    private function isSqlite(): bool
    {
        try {
            return DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 初始化关键 PRAGMA（与 PragmaConnector 保持一致）。
     */
    private function runInit(): void
    {
        $pragmas = [
            'PRAGMA journal_mode = WAL',
            'PRAGMA busy_timeout = 5000',
            'PRAGMA synchronous = NORMAL',
            'PRAGMA foreign_keys = ON',
        ];

        foreach ($pragmas as $pragma) {
            try {
                $result = DB::select($pragma);
                $extra  = $result ? (' => ' . json_encode($result[0] ?? null)) : '';
                $this->info($pragma . $extra);
            } catch (\Throwable $e) {
                $this->warn($pragma . ' 执行失败（可忽略）: ' . $e->getMessage());
            }
        }
    }

    /**
     * 更新查询优化器统计信息。PRAGMA optimize 会自行判断哪些表需要 ANALYZE。
     */
    private function runAnalyze(): void
    {
        $start = microtime(true);
        try {
            DB::statement('PRAGMA optimize');
            $this->info(sprintf('PRAGMA optimize 完成，耗时 %.2f 秒', microtime(true) - $start));
        } catch (\Throwable $e) {
            $this->warn('PRAGMA optimize 失败: ' . $e->getMessage());
        }
    }

    /**
     * VACUUM 回收空间 + ANALYZE。
     *
     * 注意：VACUUM 期间写入会被短暂阻塞（busy_timeout 到期后报错），
     * 队列任务具备重试能力，建议在凌晨低峰期执行（默认每周一次）。
     */
    private function runVacuum(): void
    {
        // 1. 先做一次 WAL checkpoint，减少 VACUUM 需要处理的增量
        try {
            DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
            $this->info('WAL checkpoint(TRUNCATE) 完成');
        } catch (\Throwable $e) {
            $this->warn('WAL checkpoint 失败（可忽略）: ' . $e->getMessage());
        }

        // 2. VACUUM 重写整个数据库文件，回收空闲页
        $start = microtime(true);
        try {
            DB::statement('VACUUM');
            $this->info(sprintf('VACUUM 完成，耗时 %.2f 秒', microtime(true) - $start));
        } catch (\Throwable $e) {
            $this->error('VACUUM 失败: ' . $e->getMessage());

            return;
        }

        // 3. ANALYZE 更新统计信息
        $start = microtime(true);
        try {
            DB::statement('ANALYZE');
            $this->info(sprintf('ANALYZE 完成，耗时 %.2f 秒', microtime(true) - $start));
        } catch (\Throwable $e) {
            $this->warn('ANALYZE 失败: ' . $e->getMessage());
        }
    }
}