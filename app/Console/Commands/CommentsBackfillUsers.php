<?php

namespace App\Console\Commands;

use App\Models\Comment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 回填评论用户信息到 comment_users（归一化表）。
 *
 * 安全性：本命令只做「加法」——仅写入 comment_users，绝不修改 comments 表，
 * 可随时中断、反复执行（幂等），对线上播放/浏览无影响。
 *
 * 典型用法（升级到新版后建议执行一次）：
 *   php artisan app:comments-backfill-users
 */
class CommentsBackfillUsers extends Command
{
    protected $signature = 'app:comments-backfill-users
        {--range=50000 : 每批处理的 mid 区间大小}';

    protected $description = '将评论中的用户信息（uname/avatar）回填到 comment_users 归一化表（只加不删）';

    public function handle(): int
    {
        $range = max(1000, (int) $this->option('range'));

        $min = Comment::query()->min('mid');
        $max = Comment::query()->max('mid');
        if ($min === null || $max === null) {
            $this->info('没有评论数据，无需回填。');

            return self::SUCCESS;
        }

        $min = (int) $min;
        $max = (int) $max;
        $this->info(sprintf('开始回填评论用户信息: mid 范围 [%d, %d]，批大小 %d', $min, $max, $range));

        $total = 0;
        for ($lo = $min; $lo <= $max; $lo += $range) {
            $hi = min($lo + $range - 1, $max);

            $affected = DB::affectingStatement(
                'INSERT OR IGNORE INTO comment_users (mid, uname, avatar, updated_at)
                 SELECT mid, MAX(uname), MAX(avatar), ?
                 FROM comments
                 WHERE mid BETWEEN ? AND ?
                   AND mid NOT IN (SELECT mid FROM comment_users)
                 GROUP BY mid',
                [now(), $lo, $hi]
            );

            $total += $affected;
            $this->line(sprintf('  mid [%d, %d] 新增 %d 行（累计 %d）', $lo, $hi, $affected, $total));
        }

        $this->info(sprintf('回填完成: 新增 %d 个用户。comment_users 总数: %d', $total, DB::table('comment_users')->count()));
        $this->line('提示: 如需回收评论表磁盘空间（清空行内冗余 uname/avatar），请运行 app:comments-reclaim-space。');

        return self::SUCCESS;
    }
}