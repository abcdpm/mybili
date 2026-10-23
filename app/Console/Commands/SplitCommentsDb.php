<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 评论库拆分独立 SQLite（批F·架构拆分，opt-in）。
 *
 * 目标：把评论三表（comments/comment_users/emotes）迁移到独立 SQLite 文件，
 * 降低主库 WAL 体积与读写竞争（生产评论量千万级、磁盘 I/O 敏感）。
 *
 * 可靠性设计（生产红线）：
 *  - opt-in：仅 --execute 显式执行；默认只读报告；
 *  - 零删除：主库旧表原样保留（只读兼容），回滚仅移除标记文件；
 *  - 双重校验：行数 + 内容聚合校验 + 随机行抽样比对，任一失败即中止不切换；
 *  - 复制期间的增量写入由"增量补偿 + 二次校验"覆盖；仍不一致则中止（可低峰重跑）；
 *  - 大库友好：源库为真实文件时走 ATTACH SQL 级批量复制（零 PHP 侧物化），
 *    否则走 PHP 分批复制（内存恒定 ~chunk 大小），全程进度条。
 *
 * 回滚预案：php artisan app:split-comments-db --rollback（删除标记即回主库旧表，
 * 一切查询立即切回；comments.sqlite 文件保留，可作为对照或下次迁移起点）。
 */
class SplitCommentsDb extends Command
{
    protected $signature = 'app:split-comments-db
                                {--status : 显示拆分状态与两库统计（只读）}
                                {--execute : 执行复制+校验+切换（opt-in）}
                                {--rollback : 回退到主库旧表（仅移除标记，不删除任何数据）}
                                {--chunk=20000 : PHP 分批复制时的每批行数}
                                {--force : 重建目标库副本（仅影响 comments.sqlite 副本，绝不触碰主库）}';

    protected $description = '把评论三表迁移到独立 SQLite（opt-in、双重校验、可回滚）';

    /** 参与拆分的三张表 */
    private const TABLES = ['comments', 'comment_users', 'emotes'];

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }
        if ($this->option('rollback')) {
            return $this->rollback();
        }
        if ($this->option('execute')) {
            return $this->executeSplit();
        }

        $this->info('评论库拆分工具（当前为只读报告，加 --execute 执行迁移）');
        $this->line('  --status    查看拆分状态与两库统计');
        $this->line('  --execute   执行复制 + 校验 + 切换（显式 opt-in）');
        $this->line('  --rollback  回退主库旧表（仅移除标记文件，不删除数据）');

        return $this->showStatus();
    }

    /**
     * 状态报告（只读）。
     */
    private function showStatus(): int
    {
        $marker = comments_db_marker_path();
        $active = comments_db_split_active();
        $target = config('database.connections.sqlite_comments.database');

        $this->info('=== 评论库拆分状态 ===');
        $this->line('拆分标记: '.($active ? '已激活' : '未激活')."（{$marker}）");
        $this->line('目标库:   '.$target.(file_exists($target) ? '（已存在 '.$this->humanSize(filesize($target)).'）' : '（未创建）'));

        foreach (self::TABLES as $table) {
            $srcCount = $this->safeCount($this->sourceConnection(), $table);
            // 目标库文件未创建时不触发连接（Laravel 11+ 拒绝自动建库；保持 --status 只读）
            $dstCount = file_exists($target) ? $this->safeCount(DB::connection('sqlite_comments'), $table) : -1;
            $this->line(sprintf('  %-14s 主库旧表: %-10s 独立库: %s', $table, $srcCount, $dstCount === -1 ? '（无）' : $dstCount));
        }

        if ($active) {
            $this->comment('提示：当前读写走独立库；主库旧表仅作遗留备份（只读）。回滚用 --rollback。');
        }

        return 0;
    }

    /**
     * 回滚：移除标记文件（零删除），读写立即切回主库旧表。
     */
    private function rollback(): int
    {
        $marker = comments_db_marker_path();
        if (! file_exists($marker)) {
            $this->warn('拆分标记不存在，当前已是主库模式，无需回滚。');

            return 0;
        }

        unlink($marker);
        comments_db_split_flush();

        $this->info('已回退：读写立即切回主库旧表（comments.sqlite 文件已保留，未删除任何数据）。');
        $this->comment('注：拆分后新写入独立库的增量数据不会自动回流主库；如需保留请先评估比对。');

        return 0;
    }

    /**
     * 执行拆分：复制 → 增量补偿 → 双重校验 → 写标记切换。
     * 任一校验失败即中止（不写标记），主库保持权威，可低峰重跑。
     */
    private function executeSplit(): int
    {
        if (comments_db_split_active()) {
            $this->warn('拆分已激活，无需重复执行（--status 查看，--rollback 回退）。');

            return 0;
        }

        $source = $this->sourceConnection();
        $targetPath = config('database.connections.sqlite_comments.database');
        // 注意：sqlite_comments 连接对象必须在目标文件 touch 之后再获取
        // （Laravel 11+ 不自动建库，提前 DB::connection() 会直接抛异常）

        // ---- 预检 ----
        $dir = dirname($targetPath);
        if (! is_dir($dir) || ! is_writable($dir)) {
            $this->error("目标目录不可写: {$dir}");

            return 1;
        }
        if (file_exists($targetPath) && filesize($targetPath) > 0 && ! $this->option('force')) {
            $this->error('目标库已存在非空文件（可能是上次中断的副本）。确认无误后用 --force 重建副本（不影响主库）。');

            return 1;
        }

        // ---- 迁移起点快照（用于校验基准）----
        $startAt = now()->toDateTimeString();
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = [
                'count' => $this->safeCount($source, $table),
                'checksum' => $this->tableChecksum($source, $table),
            ];
        }
        $this->info('起点快照: '.json_encode(array_map(fn ($s) => $s['count'], $snapshot), JSON_UNESCAPED_UNICODE));

        // ---- 重建副本 + 拷贝 schema ----
        $this->line('创建目标库与表结构…');
        $target = $this->recreateTargetSchema($source, $targetPath);

        // ---- 复制数据（源为真实文件走 ATTACH 快路径，否则 PHP 分批）----
        $sourcePath = config('database.connections.sqlite.database');
        $copied = [];
        if (is_file($sourcePath)) {
            $this->info('源库为真实文件 → ATTACH SQL 级批量复制（零 PHP 物化）');
            $copied = $this->copyViaAttach($sourcePath, $target);
        } else {
            $this->info('源库非文件（如 :memory:）→ PHP 分批复制');
            $copied = $this->copyViaPhp($source, $target);
        }

        // ---- 增量补偿：迁移期间被写入/新增的行（按 updated_at）----
        $this->line("增量补偿（updated_at >= {$startAt}）…");
        foreach ($this->deltaPass($source, $target, $startAt) as $table => $rows) {
            $this->line("  {$table}: 补偿 {$rows} 行");
        }

        // ---- 双重校验（最多两轮：首轮不一致再补偿一轮复核）----
        for ($round = 1; $round <= 2; $round++) {
            $verify = $this->verify($source, $target);
            if ($verify['ok']) {
                break;
            }
            $this->warn("第 {$round} 轮校验存在差异，执行增量补偿后复核…");
            $this->deltaPass($source, $target, $startAt);
        }

        if (! $verify['ok']) {
            $this->error('校验未通过（行数/内容不一致），已中止切换 —— 主库仍为权威数据源，可低峰重跑。');
            foreach ($verify['diffs'] as $diff) {
                $this->line('  - '.$diff);
            }

            return 1;
        }

        // ---- 写标记（此刻起读写切到独立库）----
        file_put_contents(comments_db_marker_path(), json_encode([
            'created_at' => now()->toDateTimeString(),
            'source' => $sourcePath,
            'target' => $targetPath,
            'tables' => array_map(fn ($s) => $s['count'], $snapshot),
            'copied' => $copied,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        comments_db_split_flush();

        $this->info('✅ 拆分完成并已切换：评论三表读写走独立库；主库旧表保留（只读兼容）。');
        $this->comment('回滚预案：php artisan app:split-comments-db --rollback（不删除任何数据）');

        return 0;
    }

    /** 源连接（主库）。 */
    private function sourceConnection(): \Illuminate\Database\Connection
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

    /**
     * 表内容聚合校验值：行数 + 各文本列长度总和。
     * 比单纯行数更强（能发现内容级差异），且为单条聚合 SQL（大表友好）。
     */
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

    /**
     * 重建目标库副本：删除旧副本文件（仅副本！）→ 建表 → 拷贝索引/触发器。
     */
    private function recreateTargetSchema(\Illuminate\Database\Connection $source, string $targetPath): \Illuminate\Database\Connection
    {
        DB::purge('sqlite_comments');
        if (file_exists($targetPath)) {
            unlink($targetPath); // 仅删除副本文件（--force 语义），主库绝不触碰
        }
        touch($targetPath);
        $target = DB::connection('sqlite_comments');

        // 从源库 sqlite_master 读取建表/索引语句（表先行，再索引/触发器）
        $objects = $source->table('sqlite_master')
            ->whereIn('tbl_name', self::TABLES)
            ->whereNotNull('sql')
            ->get(['type', 'name', 'sql']);

        foreach (['table', 'index', 'trigger', 'view'] as $type) {
            foreach ($objects->where('type', $type) as $obj) {
                if (stripos($obj->sql, 'sqlite_autoindex') !== false) {
                    continue;
                }
                // 建表语句剥离跨库外键（comments→videos 等引用在独立库无法成立，
                // 且部分 SQLite 配置下带未解析引用的 CREATE TABLE 会被拒绝）
                $sql = $type === 'table' ? $this->stripForeignKeyClauses($obj->sql) : $obj->sql;
                try {
                    $target->getPdo()->exec($sql);
                } catch (\Throwable $e) {
                    // 同名对象重复创建等可忽略；其余错误上抛
                    if (! str_contains($e->getMessage(), 'already exists')) {
                        throw $e;
                    }
                }
            }
        }

        return $target;
    }

    /**
     * 剥离 CREATE TABLE 中的外键子句（表级 foreign key(...) references ... 与列级 references ...）。
     * 仅用于独立评论库的建表复制 —— 主库 DDL 不受影响。
     */
    private function stripForeignKeyClauses(string $sql): string
    {
        // 表级：, foreign key(...) references "videos"("id") [on delete cascade]
        $sql = preg_replace('/,\s*foreign\s+key\s*\([^)]*\)\s*references\s+["\'`\w]+\s*\([^)]*\)(\s+on\s+delete\s+\w+(\s+\w+)?)?/i', '', $sql);
        // 列级： references "videos"("id") [on delete cascade]
        $sql = preg_replace('/\s+references\s+["\'`\w]+\s*\([^)]*\)(\s+on\s+delete\s+\w+(\s+\w+)?)?/i', '', $sql);

        return $sql;
    }

    /**
     * ATTACH 快路径：目标连接上 ATTACH 源库，按 rowid 区间 SQL 级批量复制（零 PHP 物化）。
     *
     * @return array<string, int> 每表复制行数
     */
    private function copyViaAttach(string $sourcePath, \Illuminate\Database\Connection $target): array
    {
        $pdo = $target->getPdo();
        $pdo->exec('ATTACH DATABASE '.$pdo->quote($sourcePath).' AS src_split');

        $copied = [];
        foreach (self::TABLES as $table) {
            $row = $target->table("src_split.{$table}")->selectRaw('MIN(rowid) AS lo, MAX(rowid) AS hi, COUNT(*) AS c')->first();
            $copied[$table] = (int) $row->c;
            if ($row->c == 0) {
                continue;
            }

            $chunk = max(1000, (int) $this->option('chunk'));
            $bar = $this->output->createProgressBar((int) $row->c);
            $bar->start();

            for ($lo = (int) $row->lo; $lo <= (int) $row->hi; $lo += $chunk) {
                $hi = $lo + $chunk - 1;
                $target->getPdo()->exec(
                    "INSERT INTO \"{$table}\" SELECT * FROM src_split.\"{$table}\" WHERE rowid BETWEEN {$lo} AND {$hi}"
                );
                $bar->advance(min($chunk, (int) $row->c));
            }
            $bar->finish();
            $this->newLine();
        }

        $pdo->exec('DETACH DATABASE src_split');

        return $copied;
    }

    /**
     * PHP 分批复制（源为 :memory: 或无法 ATTACH 时）：恒定内存（~chunk 行），事务批量插入。
     *
     * @return array<string, int> 每表复制行数
     */
    private function copyViaPhp(\Illuminate\Database\Connection $source, \Illuminate\Database\Connection $target): array
    {
        $chunk = max(100, (int) $this->option('chunk'));
        $copied = [];

        foreach (self::TABLES as $table) {
            $total = $this->safeCount($source, $table);
            $copied[$table] = 0;
            if ($total <= 0) {
                continue;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();
            $lastId = 0;
            $pk = $table === 'comment_users' ? 'mid' : 'id';

            while (true) {
                $rows = $source->table($table)->where($pk, '>', $lastId)->orderBy($pk)->limit($chunk)->get();
                if ($rows->isEmpty()) {
                    break;
                }

                $target->transaction(function () use ($target, $table, $rows) {
                    foreach ($rows as $row) {
                        $target->table($table)->insert((array) $row);
                    }
                });

                $copied[$table] += $rows->count();
                $bar->advance($rows->count());
                $lastId = (int) last($rows)->{$pk};

                if ($rows->count() < $chunk) {
                    break;
                }
            }

            $bar->finish();
            $this->newLine();
        }

        return $copied;
    }

    /**
     * 增量补偿：把 updated_at >= 起点的行（迁移期间新增/更新）逐行 upsert 到目标库。
     *
     * @return array<string, int> 每表补偿行数
     */
    private function deltaPass(\Illuminate\Database\Connection $source, \Illuminate\Database\Connection $target, string $startAt): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $hasUpdatedAt = in_array('updated_at', $source->getSchemaBuilder()->getColumnListing($table), true);
            if (! $hasUpdatedAt) {
                $result[$table] = 0;

                continue;
            }

            $rows = $source->table($table)->where('updated_at', '>=', $startAt)->get();
            $count = 0;
            $pk = $table === 'comment_users' ? 'mid' : 'id';
            foreach ($rows as $row) {
                $data = (array) $row;
                $target->table($table)->updateOrInsert([$pk => $data[$pk]], $data);
                $count++;
            }
            $result[$table] = $count;
        }

        return $result;
    }

    /**
     * 双重校验：行数 + 内容聚合校验 + 20 行随机抽样逐字段比对。
     *
     * @return array{ok: bool, diffs: string[]}
     */
    private function verify(\Illuminate\Database\Connection $source, \Illuminate\Database\Connection $target): array
    {
        $diffs = [];

        foreach (self::TABLES as $table) {
            $srcCount = $this->safeCount($source, $table);
            $dstCount = $this->safeCount($target, $table);
            if ($srcCount !== $dstCount) {
                $diffs[] = "{$table} 行数不一致: 主库 {$srcCount} vs 独立库 {$dstCount}";
            }

            if ($srcCount > 0 && $this->tableChecksum($source, $table) !== $this->tableChecksum($target, $table)) {
                $diffs[] = "{$table} 内容聚合校验不一致（行数相同但内容长度分布不同）";
            }

            // 随机抽样 20 行逐字段比对
            if ($srcCount > 0) {
                $pk = $table === 'comment_users' ? 'mid' : 'id';
                $samples = $source->table($table)->inRandomOrder()->limit(20)->get();
                foreach ($samples as $sample) {
                    $targetRow = $target->table($table)->where($pk, $sample->{$pk})->first();
                    if (! $targetRow || json_encode((array) $targetRow) !== json_encode((array) $sample)) {
                        $diffs[] = "{$table} 抽样行 {$pk}={$sample->{$pk}} 字段比对不一致";
                        break;
                    }
                }
            }
        }

        return ['ok' => empty($diffs), 'diffs' => $diffs];
    }

    private function humanSize(int $bytes): string
    {
        return format_file_size($bytes);
    }
}
