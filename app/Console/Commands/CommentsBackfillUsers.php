<?php

namespace App\Console\Commands;

use App\Models\Comment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 回填评论用户信息到 comment_users（归一化表）。
 *
 * 安全性：本命令只做「加法」——仅写入 comment_users（INSERT OR IGNORE，
 * 已存在的用户不覆盖），绝不修改 comments 表，可随时中断、反复执行（幂等）。
 *
 * 分批策略：游标式按「实际存在的 mid」推进（keyset 分页），
 * 不遍历数值区间空洞——B 站 mid 空间极度稀疏（老用户 mid 仅个位数、
 * 新用户 mid 为 16 位长整数，中间存在巨大空洞），数值区间遍历会产生
 * 数千万个空批。配合 comments.mid 索引，每批只扫描实际数据，
 * 未回填的 mid 经 NOT EXISTS 主键索引快速跳过。
 *
 * 典型用法（升级到新版后建议执行一次）：
 *   php artisan app:comments-backfill-users
 */
class CommentsBackfillUsers extends Command
{
    protected $signature = 'app:comments-backfill-users
        {--batch=500 : 每批处理的用户（mid）数量}';

    protected $description = '将评论中的用户信息（uname/avatar）回填到 comment_users 归一化表（只加不删）';

    public function handle(): int
    {
        $batch = max(50, (int) $this->option('batch'));

        if (Comment::query()->count() === 0) {
            $this->info('没有评论数据，无需回填。');

            return self::SUCCESS;
        }

        $this->info(sprintf('开始回填评论用户信息（游标式分批，每批 %d 个用户）...', $batch));

        $total = 0;
        $batches = 0;
        $cursor = 0;      // mid 游标（升序推进，跳过空洞）
        $start = microtime(true);

        while (true) {
            // 取下一批「实际存在且尚未回填」的 mid（comments.mid 索引有序 + comment_users 主键过滤）
            $rows = DB::select(
                'SELECT c.mid AS mid, MAX(c.uname) AS uname, MAX(c.avatar) AS avatar
                 FROM comments c
                 WHERE c.mid > ?
                   AND NOT EXISTS (SELECT 1 FROM comment_users cu WHERE cu.mid = c.mid)
                 GROUP BY c.mid
                 ORDER BY c.mid
                 LIMIT ?',
                [$cursor, $batch]
            );

            if (empty($rows)) {
                break;
            }

            $values = [];
            $bindings = [];
            foreach ($rows as $row) {
                $mid = (int) $row->mid;
                $values[] = '(?, ?, ?, ?)';
                array_push($bindings, $mid, (string) ($row->uname ?? ''), (string) ($row->avatar ?? ''), now());
                $cursor = $mid; // 游标推进到最后一个实际存在的 mid
            }

            $affected = DB::affectingStatement(
                'INSERT OR IGNORE INTO comment_users (mid, uname, avatar, updated_at) VALUES ' . implode(',', $values),
                $bindings
            );
            $total += $affected;
            $batches++;

            if ($batches % 20 === 0) {
                $this->line(sprintf(
                    '  已处理 %d 批 / 新增 %d 个用户 / 游标 mid=%d / 耗时 %.0f 秒',
                    $batches, $total, $cursor, microtime(true) - $start
                ));
            }
        }

        $this->info(sprintf('回填完成: 扫描 %d 批，新增 %d 个用户，耗时 %.0f 秒', $batches, $total, microtime(true) - $start));
        $this->info(sprintf('comment_users 总数: %d', DB::table('comment_users')->count()));

        $this->line('提示: 如需回收评论表磁盘空间（清空行内冗余 uname/avatar），请运行 app:comments-reclaim-space。');

        return self::SUCCESS;
    }
}