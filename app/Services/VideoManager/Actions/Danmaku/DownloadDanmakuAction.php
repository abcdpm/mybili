<?php
namespace App\Services\VideoManager\Actions\Danmaku;

use App\Models\Video;
use App\Models\VideoPart;
use App\Services\BilibiliService;
use App\Services\Danmaku\DanmakuFileStore;
use App\Services\DownloadFilterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DownloadDanmakuAction
{

    public function __construct(
        public BilibiliService $bilibiliService,
        public DownloadFilterService $downloadFilterService
    ) {
    }

    /**
     * 保存弹幕：写入 JSON.gz 文件（按 cid），并自动清理该 cid 的数据库遗留行。
     *
     * 性能说明：去重在内存中 O(1) 完成（哈希索引），替代了旧版
     * 全量加载 id + in_array 的 O(n²) 实现；不再逐条写数据库。
     */
    protected function saveDanmaku(string $cid, array $danmaku): void
    {
        $start_time = microtime(true);

        $videoPart = VideoPart::where('cid', $cid)->first();
        $videoId   = $videoPart ? (int) $videoPart->video_id : null;

        $count = app(DanmakuFileStore::class)->save($cid, $danmaku, $videoId);

        Log::info('Save danmaku count, time', [
            'time'  => microtime(true) - $start_time,
            'count' => $count,
            'cid'   => $cid,
        ]);
    }

    public function execute(VideoPart $videoPart): void
    {
        $start_time = microtime(true);

        //加锁
        $lock = redis()->setnx(sprintf('danmaku_downloading:%s', $videoPart->cid), 1);
        if (! $lock) {
            Log::info('Danmaku is being downloaded', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
            return;
        }
        redis()->expire(sprintf('danmaku_downloading:%s', $videoPart->cid), 3600 * 8);

        try {
            
            // 检查是否在被排除的收藏夹中, 是否在订阅中排除。
            $video = $videoPart->video;
            if ($video) {
                if ($this->downloadFilterService->shouldExcludeByFavTime($video)) {
                    Log::info('Download danmaku excluded by favorite time', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
                    return;
                }
                if ($this->downloadFilterService->shouldExcludeByVideo($video)) {
                    Log::info('Download excluded by favorite and subscription', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
                    return;
                }
            }

            Log::info('Download danmaku start', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
            $video ??= Video::where('id', $videoPart->video_id)->first();
            if (! $video) {
                Log::info('Video not found or deleted', ['id' => $videoPart->video_id]);
                return;
            }

            $partDanmakus = $this->bilibiliService->getDanmaku($videoPart->cid, intval($videoPart->duration));
            Log::info('Download danmaku time', ['time' => microtime(true) - $start_time]);

            Log::info('Download danmaku success', [
                'id'       => $videoPart->cid,
                'title'    => $videoPart->part,
                'count'    => count($partDanmakus),
                'video_id' => $video->id,
                'bvid'     => $video->bvid,
                'video_title'    => $video->title,
            ]);
            $this->saveDanmaku($videoPart->cid, $partDanmakus);
            Log::info('Save danmaku time', ['time' => microtime(true) - $start_time]);
            $videoPart->danmaku_downloaded_at = Carbon::now();
            $videoPart->save();

        } catch (\Throwable $e) {
            Log::error('Download danmaku failed', ['id' => $videoPart->cid, 'title' => $videoPart->part, 'error' => $e->getMessage()]);
            return;
        } finally {
            // 移除下载锁
            redis()->del(sprintf('danmaku_downloading:%s', $videoPart->cid));
            Log::info('Download danmaku end, take time', ['time' => microtime(true) - $start_time, 'id' => $videoPart->cid, 'title' => $videoPart->part]);
        }
    }
}
