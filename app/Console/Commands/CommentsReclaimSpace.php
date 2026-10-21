<?php

namespace App\Console\Commands;

use App\Models\Comment;
use App\Models\Emote;
use App\Services\Comment\EmoteResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 回收评论表磁盘空间（数据归一化后的冗余清理）。
 *
 * 处理内容（canonical 数据均已保留在 comment_users / emotes 表）：
 *   1. 补跑 comment_users 回填（确保清空行内列之前数据已安全归一化）；
 *   2. 清空 comments 行内冗余的 uname / avatar；
 *   3. 将旧版表情包完整映射压缩为 key 列表（展示时经 EmoteResolver 还原）；
 *   4. 可选 --vacuum 真正释放数据库文件空间。
 *
 * ⚠️ 注意：此命令会改写 comments 行内容（数据不丢失，但属单向操作），
 * 请在确认升级稳定后再执行；可分批反复执行（幂等）。
 */
class CommentsReclaimSpace extends Command
{
    protected $signature = 'app:comments-reclaim-space
        {--chunk=1000 : 每批处理的评论行数}
        {--vacuum : 清理完成后执行 VACUUM 真正释放磁盘空间}';

    protected $description = '回收评论表冗余空间：清空行内 uname/avatar、压缩表情映射（数据已归一化保留）';

    public function handle(EmoteResolver $resolver): int
    {
        $chunk = max(100, (int) $this->option('chunk'));

        // 0. 先确保用户信息已归一化（只加不删的安全操作）
        $this->call('app:comments-backfill-users');

        // 1. 清空行内冗余 uname/avatar（仅限 comment_users 已有该用户的行）
        $this->info('开始清空 comments 行内冗余的 uname/avatar ...');
        $cleared = 0;
        $minId = Comment::query()->min('id');
        $maxId = Comment::query()->max('id');

        if ($minId !== null) {
            for ($lo = (int) $minId; $lo <= (int) $maxId; $lo += $chunk) {
                $hi = min($lo + $chunk - 1, (int) $maxId);
                $cleared += DB::affectingStatement(
                    "UPDATE comments SET uname = '', avatar = ''
                     WHERE id BETWEEN ? AND ?
                       AND (uname != '' OR avatar != '')
                       AND mid IN (SELECT mid FROM comment_users)",
                    [$lo, $hi]
                );
                $this->line(sprintf('  进度 id <= %d，已清理 %d 行', $hi, $cleared));
            }
        }
        $this->info(sprintf('uname/avatar 冗余清理完成: %d 行', $cleared));

        // 2. 压缩旧版表情包完整映射为 key 列表
        $this->info('开始压缩旧版表情包映射 ...');
        $compacted = 0;
        $skipped   = 0;

        Comment::query()
            ->whereNotNull('emotes')
            ->where('emotes', 'LIKE', '{%')
            ->orderBy('id')
            ->chunkById($chunk, function ($comments) use ($resolver, &$compacted, &$skipped) {
                foreach ($comments as $comment) {
                    $emotes = $comment->emotes;
                    if (! is_array($emotes) || empty($emotes) || array_is_list($emotes)) {
                        continue;
                    }

                    $keys = array_keys($emotes);
                    // 仅当全部为已本地化的双路径对象、且 key 均可在 emotes 表解析时才压缩
                    $allLocalized = ! in_array(false, array_map(
                        fn ($v) => is_array($v) && isset($v['webp']),
                        $emotes
                    ), true);
                    $resolvable = count($resolver->expand($keys)) === count($keys);

                    if ($allLocalized && $resolvable) {
                        DB::table('comments')->where('id', $comment->id)->update([
                            'emotes' => json_encode(array_values($keys), JSON_UNESCAPED_UNICODE),
                        ]);
                        $compacted++;
                    } else {
                        $skipped++;
                    }
                }
                $this->line(sprintf('  已压缩 %d 条（保留 %d 条不可压缩）', $compacted, $skipped));
            });

        $this->info(sprintf('表情映射压缩完成: 压缩 %d 条，保留 %d 条', $compacted, $skipped));

        // 3. 可选 VACUUM
        if ($this->option('vacuum')) {
            $this->info('执行 VACUUM 释放磁盘空间（大库可能需要几分钟）...');
            $this->call('app:database-maintenance', ['--vacuum' => true]);
        } else {
            $this->line('提示: 加 --vacuum 可立即释放数据库文件空间（或等待每周自动 VACUUM）。');
        }

        return self::SUCCESS;
    }
}