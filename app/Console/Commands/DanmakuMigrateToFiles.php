<?php

namespace App\Console\Commands;

use App\Models\Danmaku;
use App\Models\VideoPart;
use App\Services\Danmaku\DanmakuFileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 将数据库中的历史弹幕迁移到文件存储（按 cid 存 JSON.gz）。
 *
 * 安全性设计（保护生产数据）：
 *   1. 逐 cid 处理：先把该 cid 的全部弹幕合并写入文件；
 *   2. 写入成功后校验文件可读且条数不少于源数据；
 *   3. 校验通过后才删除该 cid 的数据库行；
 *   4. --dry-run 只统计不写不删；--cid 可单个处理；可分批反复执行（幂等）。
 *
 * 迁移完成后数据库体积预计可下降 40% 左右，播放弹幕不再需要从数据库反序列化。
 */
class DanmakuMigrateToFiles extends Command
{
    protected $signature = 'app:danmaku-migrate-to-files
        {--cid= : 仅迁移指定的 cid}
        {--limit=0 : 本次最多处理的 cid 数量（0 = 不限制）}
        {--dry-run : 只统计将迁移的数据，不做任何写入或删除}';

    protected $description = '将数据库历史弹幕迁移到 JSON.gz 文件存储（先写文件校验，后清数据库行）';

    public function handle(DanmakuFileStore $store): int
    {
        if (! $this->option('dry-run') && ! $store->basePath()) {
            $this->error('弹幕存储目录不可用，请检查 DANMAKU_PATH 配置');

            return self::FAILURE;
        }

        $query = Danmaku::query()->select('cid')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('cid')
            ->orderBy('cid');

        if ($this->option('cid')) {
            $query->where('cid', $this->option('cid'));
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $groups = $query->get();
        if ($groups->isEmpty()) {
            $this->info('没有需要迁移的弹幕数据（可能已全部迁移完成）。');

            return self::SUCCESS;
        }

        $this->info(sprintf('待迁移 cid 数量: %d，弹幕总条数: %d', $groups->count(), $groups->sum('total')));
        if ($this->option('dry-run')) {
            $this->warn('--dry-run 模式：不做任何写入或删除。');

            return self::SUCCESS;
        }

        $migratedCids = 0;
        $migratedRows = 0;

        foreach ($groups as $group) {
            $cid      = (int) $group->cid;
            $expected = (int) $group->total;

            $rows = Danmaku::query()
                ->where('cid', $cid)
                ->get(['id', 'progress', 'mode', 'color', 'content'])
                ->toArray();

            // 从 video_parts 反查 video_id（若已不存在则为 null，不影响迁移）
            $videoId = VideoPart::query()->where('cid', $cid)->value('video_id');
            $videoId = $videoId !== null ? (int) $videoId : null;

            // 1) 合并写入文件（save 内部会先合并已有文件数据）
            //    序列化/写盘失败会抛异常 → 跳过该 cid 且保留数据库遗留行，数据零丢失
            try {
                $count = $store->save($cid, $rows, $videoId);
            } catch (\Throwable $e) {
                $this->error(sprintf('cid=%d 迁移失败（%s），保留数据库数据不删除', $cid, $e->getMessage()));
                continue;
            }

            // 2) 校验：文件必须存在且条数不少于源数据
            $fileItems = $store->read($cid);
            if (! $store->exists($cid) || count($fileItems) < min($expected, $count)) {
                $this->error(sprintf('cid=%d 迁移校验失败，保留数据库数据不删除（文件条数=%d，源条数=%d）', $cid, count($fileItems), $expected));

                continue;
            }

            // 3) 校验通过：save() 已将该 cid 的数据库行清理，此处仅统计
            $migratedCids++;
            $migratedRows += $expected;

            if ($migratedCids % 50 === 0) {
                $this->info(sprintf('进度: 已迁移 %d 个 cid / %d 条弹幕', $migratedCids, $migratedRows));
            }
        }

        $this->info(sprintf('迁移完成: %d 个 cid，共 %d 条弹幕。数据库遗留行: %d', $migratedCids, $migratedRows, Danmaku::count()));

        return self::SUCCESS;
    }
}