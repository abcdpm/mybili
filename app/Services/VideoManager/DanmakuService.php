<?php
namespace App\Services\VideoManager;

use App\Models\Danmaku;
use App\Models\DanmakuFile;
use App\Models\Video;
use App\Services\Danmaku\DanmakuFileStore;
use App\Services\VideoManager\Contracts\DanmakuServiceInterface;

class DanmakuService implements DanmakuServiceInterface
{
    /**
     * 统计视频弹幕总数：文件化弹幕（danmaku_files 索引）+ 数据库遗留行。
     * 两条查询替代旧版「每分 P 一次 count」的 N+1 实现。
     */
    public function getVideoDanmakuCount(Video $video): int
    {
        $cids = $video->parts->pluck('cid')->all();
        if (empty($cids)) {
            return 0;
        }

        $legacyCount = Danmaku::query()->whereIn('cid', $cids)->count();
        $fileCount   = (int) DanmakuFile::query()->whereIn('cid', $cids)->sum('count');

        return $legacyCount + $fileCount;
    }

    /**
     * 读取弹幕：文件优先，数据库遗留行兜底（迁移期间双读兼容）。
     */
    public function getDanmaku(string $cid): array
    {
        return app(DanmakuFileStore::class)->merged($cid);
    }
}