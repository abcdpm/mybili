<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 主库瘦身：评论三表旧表归档（批F 后续·opt-in）。
 *
 * 背景：评论库拆分后，主库中的 comments/comment_users/emotes 旧表成为
 * "只读遗留备份"（约数 GB 死重量）。本命令把它们归档到独立文件并按需回收空间。
 *
 * 安全设计（删除显式、全程可恢复）：
 *  - 前置硬闸门：必须处于"拆分已激活"状态（独立库是权威数据源），
 *    且独立库行数与主库旧表一致，否则拒绝执行；
 *  - --execute 仅归档（复制 + 三重校验），不删除任何东西；
 *  - --drop 必须与 --execute 同用，且校验通过后才 DROP 旧表 + VACUUM 回收；
 *  - --restore 可从归档文件把三表完整恢复回主库（误删/回退防呆修复），
 *    与 app:split-comments-db --rollback 的防呆闸门联动；
 *  - 归档文件默认落主库同目录（持久化卷），可用 --archive 自定义。
 */
class ArchiveLegacyComments extends Command
{
    protected $signature = 'app:archive-legacy-comments
                                {--status : 只读报告（行数/体积/可回收空间估算）}
                                {--execute : 归档三表到独立归档文件（复制+校验，不删除）}
                                {--drop : 与 --execute 同用：校验通过后删除主库旧表并 VACUUM 回收空间}
                                {--archive= : 归档文件路径（默认主库同目录 comments-legacy-archive-时间戳.sqlite）}
                                {--restore : 从归档恢复主库旧表（防呆修复）}
                                {--from= : --restore 的来源归档文件}
                                {--chunk=20000 : 分批复制行数}';

    protected $description = '把主库评论三表旧表归档到独立文件并按需回收空间（opt-in、三重校验、可恢复）';

    private const TABLES = ['comments', 'comment_users', 'emotes'];

    public function handle(): int
    {
        if ($this->option('restore')) {
            return $this->restore();
        }
        if ($this->option('execute')) {
            return $this->executeArchive();
        }

        $this->info('评论旧表归档工具（只读报告；--execute 归档，--execute --drop 归档并回收空间）');

        return $this->showStatus();
    }

    /**
     * 只读报告：两侧行数、主库体积、可回收空间估算。
     */
    private function showStatus(): int
    {
        $main = $this->main();
        $mainPath = config('database.connections.sqlite.database');
        $splitActive = comments_db_split_active();

        $this->info('=== 评论旧表归档状态 ===');
        $this->line('拆分状态: '.($splitActive ? '已激活（独立库为权威数据源，可安全归档旧表）' : '未激活 —— 旧表仍是活数据，禁止归档/删除'));
        $this->line('主库文件: '.$mainPath.(file_exists($mainPath) ? '（'.$this->humanSize((int) filesize($mainPath)).'）' : ''));

        $estimatedBytes = 0;
        foreach (self::TABLES as $table) {
            $count = $this->safeCount($main, $table);
            $bytes = $this->estimateTableBytes($main, $table);
            $estimatedBytes += $bytes;
            $this->line(sprintf('  %-14s 主库旧表: %-10s 估算体积: %s', $table, $count, $this->humanSize($bytes)));
        }
        $this->line('可回收估算: 约 '.$this->humanSize($estimatedBytes).'（DROP 旧表 + VACUUM 后释放）');

        return 0;
    }

    /**
     * 归档执行：复制 → 三重校验 →（--drop 时）删除旧表 + VACUUM。
     */
    private function executeArchive(): int
    {
        // 硬闸门 1：必须拆分已激活（否则旧表是活数据）
        if (! comments_db_split_active()) {
            $this->error('拆分未激活：主库旧表仍是活数据，禁止归档。请先完成 app:split-comments-db --execute。');

            return 1;
        }

        $main = $this->main();
        $mainPath = config('database.connections.sqlite.database');

        // 硬闸门 2：独立库行数必须与主库旧表一致（确认数据已在权威库中）
        $live = DB::connection('sqlite_comments');
        foreach (self::TABLES as $table) {
            $src = $this->safeCount($main, $table);
            $dst = $this->safeCount($live, $table);
            if ($src !== $dst) {
                $this->error("安全闸门：{$table} 主库旧表 {$src} 行与独立库 {$dst} 行不一致，拒绝归档（请先排查并重跑拆分校验）");

                return 1;
            }
        }

        // 归档文件（默认主库同目录 = 持久化卷）
        $archivePath = $this->option('archive')
            ?: dirname($mainPath).'/comments-legacy-archive-'.date('Ymd-His').'.sqlite';
        if (file_exists($archivePath)) {
            $this->error("归档文件已存在: {$archivePath}（请更换 --archive 路径）");

            return 1;
        }

        $this->info("归档目标: {$archivePath}");
        touch($archivePath);
        config(['database.connections.sqlite_legacy_archive' => [
            'driver' => 'sqlite', 'database' => $archivePath, 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('sqlite_legacy_archive');
        $archive = DB::connection('sqlite_legacy_archive');

        // 建表（剥离跨库外键）+ keyset 批量复制
        $this->copySchema($main, $archive);
        $this->line('复制三表（keyset 批量，零 PHP 物化）…');
        $copied = $this->copyTablesKeyset($mainPath, $archive);

        // 三重校验：行数 + 内容聚合 + 随机抽样
        $verify = $this->verifyTables($main, $archive);
        if (! $verify['ok']) {
            $this->error('归档校验未通过，主库旧表未做任何改动。归档文件可删除后重试。');
            foreach ($verify['diffs'] as $diff) {
                $this->line('  - '.$diff);
            }

            return 1;
        }
        $this->info('✅ 归档校验通过（行数 + 内容聚合 + 抽样三重一致）: '.json_encode($copied));

        if (! $this->option('drop')) {
            $this->comment('当前为"仅归档"模式，主库旧表未删除。确认无误后可执行：');
            $this->comment('  php artisan app:archive-legacy-comments --execute --drop --archive='.$archivePath);

            return 0;
        }

        // ---- 显式删除 + 空间回收（仅在归档校验通过后到达此处）----
        $this->warn('即将删除主库旧表并 VACUUM 回收空间…');
        foreach (self::TABLES as $table) {
            $main->getPdo()->exec("DROP TABLE IF EXISTS \"{$table}\"");
            $this->line("  已删除主库旧表: {$table}");
        }
        $main->getPdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->line('执行 VACUUM 回收磁盘空间（大库需几分钟，期间写入短暂阻塞）…');
        $main->getPdo()->exec('VACUUM');

        $this->info('✅ 瘦身完成：主库旧表已删除并回收空间，归档保留在 '.$archivePath);
        $this->comment('恢复预案：php artisan app:archive-legacy-comments --restore --from='.$archivePath);

        return 0;
    }

    /**
     * 从归档恢复主库旧表（防呆修复：与 split --rollback 闸门联动）。
     */
    private function restore(): int
    {
        $from = (string) $this->option('from');
        if ($from === '' || ! file_exists($from)) {
            $this->error('请通过 --from= 指定存在的归档文件');

            return 1;
        }

        $main = $this->main();
        $mainPath = config('database.connections.sqlite.database');
        config(['database.connections.sqlite_legacy_archive' => [
            'driver' => 'sqlite', 'database' => $from, 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('sqlite_legacy_archive');
        $archive = DB::connection('sqlite_legacy_archive');

        foreach (self::TABLES as $table) {
            if ($main->getSchemaBuilder()->hasTable($table)) {
                $this->error("主库已存在 {$table} 表（恢复会覆盖），为避免误操作请先手工确认/处理（DROP 后重试）");

                return 1;
            }
        }

        $this->info("从归档恢复三表到主库: {$from}");
        $this->copySchema($archive, $main);
        $this->copyTablesKeyset($from, $main);

        $verify = $this->verifyTables($archive, $main);
        if (! $verify['ok']) {
            $this->error('恢复校验未通过，请检查归档文件完整性');
            foreach ($verify['diffs'] as $diff) {
                $this->line('  - '.$diff);
            }

            return 1;
        }

        $this->info('✅ 恢复完成并校验一致：主库旧表已还原（可继续 app:split-comments-db --rollback）');

        return 0;
    }

    /** 主库连接。 */
    private function main(): \Illuminate\Database\Connection
    {
        return DB::connection(config('database.default'));
    }

    /** 安全取行数（表不存在返回 -1）。 */
    private function safeCount(\Illuminate\Database\Connection $connection, string $table): int
    {
        try {
            return (int) $connection->table($table)->count();
        } catch (\Throwable) {
            return -1;
        }
    }

    /** 表体积估算：各文本列长度总和 + 行开销（用于"可回收空间"报告）。 */
    private function estimateTableBytes(\Illuminate\Database\Connection $connection, string $table): int
    {
        try {
            $columns = $connection->getSchemaBuilder()->getColumnListing($table);
            $parts = [];
            foreach ($columns as $col) {
                $parts[] = "SUM(LENGTH(COALESCE(\"{$col}\", '')))";
            }
            $parts[] = 'COUNT(*) * 16'; // 行/索引开销的粗略补偿
            $row = $connection->table($table)->selectRaw(implode(' + ', $parts))->first();

            return (int) collect((array) $row)->first();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function humanSize(int $bytes): string
    {
        return format_file_size($bytes);
    }

    /**
     * 拷贝三表表结构（剥离跨库外键：references 主库表的约束在独立文件无法成立）。
     * 逻辑与 SplitCommentsDb 保持一致（一次性工具，各自内聚）。
     */
    private function copySchema(\Illuminate\Database\Connection $from, \Illuminate\Database\Connection $to): void
    {
        $objects = $from->table('sqlite_master')
            ->whereIn('tbl_name', self::TABLES)
            ->whereNotNull('sql')
            ->get(['type', 'name', 'sql']);

        foreach (['table', 'index', 'trigger', 'view'] as $type) {
            foreach ($objects->where('type', $type) as $obj) {
                $sql = $type === 'table' ? $this->stripForeignKeys($obj->sql) : $obj->sql;
                try {
                    $to->getPdo()->exec($sql);
                } catch (\Throwable $e) {
                    if (! str_contains($e->getMessage(), 'already exists')) {
                        throw $e;
                    }
                }
            }
        }
    }

    /** 剥离 CREATE TABLE 中的外键子句。 */
    private function stripForeignKeys(string $sql): string
    {
        $sql = preg_replace('/,\s*foreign\s+key\s*\([^)]*\)\s*references\s+["\'`\w]+\s*\([^)]*\)(\s+on\s+delete\s+\w+(\s+\w+)?)?/i', '', $sql);
        $sql = preg_replace('/\s+references\s+["\'`\w]+\s*\([^)]*\)(\s+on\s+delete\s+\w+(\s+\w+)?)?/i', '', $sql);

        return $sql;
    }

    /**
     * ATTACH keyset 批量复制（零 PHP 物化；批次数 = 行数/chunk，与键密度无关）。
     *
     * @return array<string, int> 每表复制行数
     */
    private function copyTablesKeyset(string $sourcePath, \Illuminate\Database\Connection $target): array
    {
        $pdo = $target->getPdo();
        $pdo->exec('ATTACH DATABASE '.$pdo->quote($sourcePath).' AS arch_src');

        $copied = [];
        $chunk = max(1000, (int) $this->option('chunk'));
        foreach (self::TABLES as $table) {
            $pk = $table === 'comment_users' ? 'mid' : 'id';
            $count = (int) $target->table("arch_src.{$table}")->count();
            $copied[$table] = $count;
            if ($count === 0) {
                continue;
            }

            $bar = $this->output->createProgressBar($count);
            $bar->start();
            $last = 0;
            while (true) {
                $affected = (int) $pdo->exec(
                    "INSERT INTO \"{$table}\" SELECT * FROM arch_src.\"{$table}\" WHERE \"{$pk}\" > {$last} ORDER BY \"{$pk}\" LIMIT {$chunk}"
                );
                if ($affected <= 0) {
                    break;
                }
                $bar->advance($affected);
                $last = (int) $target->table($table)->max($pk);
                if ($affected < $chunk) {
                    break;
                }
            }
            $bar->finish();
            $this->newLine();
        }

        $pdo->exec('DETACH DATABASE arch_src');

        return $copied;
    }

    /**
     * 三重校验：行数 + 内容聚合 + 20 行随机抽样逐字段比对。
     *
     * @return array{ok: bool, diffs: string[]}
     */
    private function verifyTables(\Illuminate\Database\Connection $a, \Illuminate\Database\Connection $b): array
    {
        $diffs = [];

        foreach (self::TABLES as $table) {
            $ca = $this->safeCount($a, $table);
            $cb = $this->safeCount($b, $table);
            if ($ca !== $cb) {
                $diffs[] = "{$table} 行数不一致: {$ca} vs {$cb}";
            }
            if ($ca > 0 && $this->tableChecksum($a, $table) !== $this->tableChecksum($b, $table)) {
                $diffs[] = "{$table} 内容聚合校验不一致";
            }
            if ($ca > 0) {
                $pk = $table === 'comment_users' ? 'mid' : 'id';
                foreach ($a->table($table)->inRandomOrder()->limit(20)->get() as $sample) {
                    $other = $b->table($table)->where($pk, $sample->{$pk})->first();
                    if (! $other || json_encode((array) $other) !== json_encode((array) $sample)) {
                        $diffs[] = "{$table} 抽样行 {$pk}={$sample->{$pk}} 比对不一致";
                        break;
                    }
                }
            }
        }

        return ['ok' => empty($diffs), 'diffs' => $diffs];
    }

    /** 表内容聚合校验值：行数 + 前 6 个非主键列长度总和。 */
    private function tableChecksum(\Illuminate\Database\Connection $connection, string $table): string
    {
        $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        $textCols = array_values(array_filter($columns, fn ($c) => ! in_array($c, ['id', 'mid'], true)));

        $parts = ['COUNT(*)'];
        foreach (array_slice($textCols, 0, 6) as $col) {
            $parts[] = "SUM(LENGTH(COALESCE(\"{$col}\", '')))";
        }
        $row = $connection->table($table)->selectRaw(implode(', ', $parts))->first();

        return collect((array) $row)->implode('|');
    }
}
