<?php
namespace App\Services\VideoManager;

use App\Models\Danmaku;
use App\Models\DanmakuFile;
use App\Models\Video;
use App\Services\Danmaku\DanmakuFileStore;
use App\Services\DanmakuConverterService;
use App\Services\VideoManager\Contracts\DanmakuServiceInterface;
use Illuminate\Support\Facades\Cache;

class DanmakuService implements DanmakuServiceInterface
{
    /** 转换结果缓存时长：弹幕内容变更会改变指纹（缓存键），不存在脏读 */
    private const CONVERTED_CACHE_TTL = 604800; // 7 天

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
     * 读取弹幕原始数据：文件优先，数据库遗留行兜底（迁移期间双读兼容）。
     */
    public function getDanmaku(string $cid): array
    {
        return app(DanmakuFileStore::class)->merged($cid);
    }

    /**
     * 获取「转换后」的播放器弹幕（第3项性能优化）。
     *
     * 热门分 P 数万条弹幕，此前每次播放都要：全量读取 + 逐条转换。
     * 现在转换结果按「内容指纹」缓存（Redis/文件缓存均可），
     * 相同内容重复播放直接命中缓存；内容更新（下载/迁移/删除）会改变指纹，
     * 自动使用新键，旧缓存 TTL 过期回收——无需主动失效、无脏读。
     *
     * @return array{data: array, etag: string} data=播放器弹幕数组; etag=内容指纹（用于 HTTP 协商缓存）
     */
    public function getConvertedDanmaku(string $cid): array
    {
        $etag     = $this->danmakuEtag($cid);
        $cacheKey = 'danmaku:conv:' . $cid . ':' . $etag;

        $converted = Cache::get($cacheKey);
        if (! is_array($converted)) {
            $converted = app(DanmakuConverterService::class)->convert($this->getDanmaku($cid));
            Cache::put($cacheKey, $converted, self::CONVERTED_CACHE_TTL);
        }

        return ['data' => $converted, 'etag' => $etag];
    }

    /**
     * 弹幕内容指纹：文件元数据（条数/更新时间）+ 遗留行条数。
     * 仅 2 次轻量查询（均有索引），不加载弹幕内容本身。
     */
    private function danmakuEtag(string $cid): string
    {
        $meta = DanmakuFile::query()->where('cid', $cid)->first(['count', 'updated_at']);
        $legacyCount = Danmaku::query()->where('cid', $cid)->count();

        return md5(implode('|', [
            $cid,
            $meta ? $meta->count : 0,
            $meta && $meta->updated_at ? $meta->updated_at->timestamp : 0,
            $legacyCount,
        ]));
    }
}