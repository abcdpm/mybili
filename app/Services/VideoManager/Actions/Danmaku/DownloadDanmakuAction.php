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
            // 【第10项】异常分类：可重试（网络/磁盘/风控）→ 抛出交给队列重试
            // （DownloadDanmakuJob: tries=3, backoff=[60,300,600]），避免"任务显示成功
            // 但弹幕实际丢失"的静默吞错；明确的业务性错误记录后跳过。
            if ($this->isRetryableException($e)) {
                Log::warning('Download danmaku failed (retryable, rethrow to queue)', [
                    'id'    => $videoPart->cid,
                    'title' => $videoPart->part,
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
                throw $e;
            }

            Log::error('Download danmaku failed (non-retryable, skipped)', [
                'id'    => $videoPart->cid,
                'title' => $videoPart->part,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return;
        } finally {
            // 移除下载锁
            redis()->del(sprintf('danmaku_downloading:%s', $videoPart->cid));
            Log::info('Download danmaku end, take time', ['time' => microtime(true) - $start_time, 'id' => $videoPart->cid, 'title' => $videoPart->part]);
        }
    }

    /**
     * 异常分类（第10项）：判断是否应交由队列重试。
     *
     * 默认可重试——宁可多试一次也不丢弹幕（重试次数由 Job $tries=3 限界、
     * backoff=[60,300,600] 阶梯退避）；仅明确的业务性/参数性错误不重试。
     */
    private function isRetryableException(\Throwable $e): bool
    {
        if ($e instanceof \InvalidArgumentException) {
            return false; // 参数错误，重试无意义
        }
        if ($e instanceof \App\Exceptions\ApiGetVideoStatusException) {
            return false; // 稿件不可见/审核中等业务状态，重试无意义
        }

        return true;
    }
}
