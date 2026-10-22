<?php

namespace App\Services\VideoManager;

use App\Events\VideoUpdated;
use App\Models\AudioPart;
use App\Models\Comment;
use App\Models\Coverables;
use App\Models\Danmaku;
use App\Models\DanmakuFile;
use App\Models\Video;
use App\Models\VideoPart;
use App\Services\Danmaku\DanmakuFileStore;
use App\Services\DownloadQueueService;
use App\Services\DownloadVideoService;
use App\Services\VideoManager\Contracts\VideoServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Log;

/**
 * 视频领域主服务（列表查询/详情组装/统计聚合）。
 *
 * 性能要点：
 *  - getVideosByPage：统计信息以单条 SUM(CASE) 聚合查询替代逐状态 count（4×N → 1）；
 *  - 详情接口预取 tags/emotes（EmoteResolver 批量 prime），序列化期零散查询；
 *  - 弹幕/评论等大文本不进入列表投影，列表接口保持轻量。
 */
class VideoService implements VideoServiceInterface
{
    public $ttl = 86400; // 1 day

    public function count(): int
    {
        return Video::count();
    }

    public function getVideoInfo(string $id, bool $withParts = false): ?Video
    {
        $video = Video::query()->where('id', $id)->first();

        if ($video && $withParts) {
            $video->load('parts');
        }

        return $video;
    }

    public function getVideos(array $conditions = []): Collection
    {
        // 显式预加载封面关系，防止在 CLI/队列上下文（无全局预加载）出现 N+1
        $query = Video::query()->with('coverImage');
        if (isset($conditions['invalid'])) {
            $query->where('invalid', $conditions['invalid']);
        }
        if (isset($conditions['frozen'])) {
            $query->where('frozen', $conditions['frozen']);
        }
        // 默认按时间逆序排列：优先使用 fav_time，如果不存在或为 null 则使用 created_at
        $query->orderByRaw('COALESCE(fav_time, created_at) DESC');

        return $query->get();
    }

    public function getVideosByPage(array $conditions = [], $page = 1, $perPage = 10): array
    {
        $query = Video::query();
        if (isset($conditions['query'])) {
            if (str_starts_with(strtolower($conditions['query']), 'bv')) {
                $query->where('bvid', $conditions['query']);
            } elseif (preg_match('/^\d+$/', $conditions['query'])) {
                $query->where('id', $conditions['query']);
            } else {
                $query->where('title', 'like', '%'.$conditions['query'].'%');
            }
        }

        if (isset($conditions['status'])) {
            if ($conditions['status'] == 'valid') {
                $query->where('invalid', 0);
            } elseif ($conditions['status'] == 'invalid') {
                $query->where('invalid', 1);
            } elseif ($conditions['status'] == 'frozen') {
                $query->where('frozen', 1);
            }
        }

        if (isset($conditions['downloaded'])) {
            if ($conditions['downloaded'] == 'yes') {
                $query->where('video_downloaded_num', '>', 0);
            } elseif ($conditions['downloaded'] == 'no') {
                $query->where('video_downloaded_num', 0);
            }
        }

        if (isset($conditions['multi_part'])) {
            if ($conditions['multi_part'] == 'yes') {
                $query->whereHas('parts', function ($query) {
                    $query->where('page', '>', 1);
                });
            } elseif ($conditions['multi_part'] == 'no') {
                $query->whereDoesntHave('parts', function ($query) {
                    $query->where('page', 1);
                });
            }
        }

        if (isset($conditions['fav_id'])) {
            if (intval($conditions['fav_id']) > 0) {
                $query->whereHas('favorite', function ($query) use ($conditions) {
                    $query->where('id', $conditions['fav_id']);
                });
            } elseif (intval($conditions['fav_id']) < 0) {
                $query->whereHas('subscriptions', function ($query) use ($conditions) {
                    $query->where('id', abs($conditions['fav_id']));
                });
            }
        }

        // 【第5项优化】旧版对同一过滤条件执行 5 次 count()（含 LIKE 时各自全表扫描），
        // 现聚合成 1 次聚合查询；reorder() 去掉排序避免聚合时的无谓排序开销
        $statRow = (clone $query)
            ->reorder()
            ->selectRaw('
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN video_downloaded_num > 0 THEN 1 ELSE 0 END), 0) AS downloaded,
                COALESCE(SUM(CASE WHEN invalid = 1 THEN 1 ELSE 0 END), 0) AS invalid,
                COALESCE(SUM(CASE WHEN invalid = 0 THEN 1 ELSE 0 END), 0) AS valid,
                COALESCE(SUM(CASE WHEN frozen = 1 THEN 1 ELSE 0 END), 0) AS frozen
            ')
            ->toBase()
            ->first();

        $stat = [
            'count' => (int) ($statRow->total ?? 0),
            'downloaded' => (int) ($statRow->downloaded ?? 0),
            'invalid' => (int) ($statRow->invalid ?? 0),
            'valid' => (int) ($statRow->valid ?? 0),
            'frozen' => (int) ($statRow->frozen ?? 0),
        ];

        // 【第18项】列表排序：白名单映射列名防注入；不传 sort 保持旧版自然顺序。
        // 弹幕数排序走 danmaku_files.count 的相关子查询（video_id 有索引），
        // 仅在用户主动选择该排序时才付出该开销，不影响默认列表。
        $sortMap = [
            'view' => 'view',
            'duration' => 'duration',
            'pubtime' => 'pubtime',
            'favtime' => 'fav_time',
        ];
        if (isset($conditions['sort'])) {
            $direction = ($conditions['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            if (isset($sortMap[$conditions['sort']])) {
                $query->orderBy($sortMap[$conditions['sort']], $direction);
            } elseif ($conditions['sort'] === 'danmaku') {
                $query->orderByRaw(
                    '(SELECT COALESCE(SUM(df.count), 0) FROM danmaku_files df WHERE df.video_id = videos.id) '.$direction
                );
            }
        }

        return [
            'list' => $query->offset(($page - 1) * $perPage)->limit($perPage)->get(),
            'stat' => $stat,
        ];
    }

    public function getAllPartsVideoForUser(Video $video): array
    {
        if ($video->isAudio()) {
            $audioPart = $video->audioPart;
            if (! $audioPart) {
                return [];
            }

            return [[
                'id' => $audioPart->sid,
                'part' => 1,
                'url' => $audioPart->audio_download_url,
                'title' => $video->title,
                'downloaded' => (bool) $audioPart->audio_download_path,
            ]];
        }

        $list = [];
        foreach ($video->parts as $videoPart) {
            $list[] = [
                'id' => $videoPart['cid'],
                'part' => $videoPart['page'],
                'url' => $videoPart['video_download_url'] ?: null,
                'mobile_url' => $videoPart['mobile_url'],
                'title' => $videoPart['part'] ?? 'P'.$videoPart['page'],
                'downloaded' => (bool) $videoPart['video_download_path'],
            ];
        }

        return $list;
    }

    public function getVideosStat(array $conditions = []): array
    {
        $stats = Video::selectRaw('
            COUNT(*) as count,
            SUM(CASE WHEN video_downloaded_num > 0 THEN 1 ELSE 0 END) as downloaded,
            SUM(CASE WHEN invalid = 1 THEN 1 ELSE 0 END) as invalid,
            SUM(CASE WHEN invalid = 0 THEN 1 ELSE 0 END) as valid,
            SUM(CASE WHEN frozen = 1 THEN 1 ELSE 0 END) as frozen
        ')->first();

        return [
            'count' => (int) $stats->count,
            'downloaded' => (int) $stats->downloaded,
            'invalid' => (int) $stats->invalid,
            'valid' => (int) $stats->valid,
            'frozen' => (int) $stats->frozen,
        ];
    }

    public function getVideoPartFileSize(VideoPart $videoPart): int
    {
        $filePath = app(DownloadVideoService::class)->getVideoPartValidFilePath($videoPart);
        if ($filePath) {
            return filesize($filePath);
        }

        return 0;
    }

    public function deleteVideos(array $ids, array $options = []): array
    {
        $permanentDelete = (bool) ($options['permanent'] ?? true);
        $requeueAfterDelete = (bool) ($options['requeue'] ?? false);
        $deletedIds = [];
        $videos = Video::query()->whereIn('id', $ids)->get();

        foreach ($videos as $video) {
            $this->removeVideoFilesAndResetState($video);

            if ($permanentDelete) {
                // 彻底删除：清理全部关联数据（弹幕/评论/分P/关联表/封面关系）后强制删除视频记录。
                // 注意：旧版本此处实为软删除且不清理评论，会产生孤儿数据；
                // 历史遗留的软删除视频请使用 php artisan app:cleanup-orphan-data 清理（默认仅报告）。
                $this->purgeVideoData($video);
                if ($video->forceDelete()) {
                    $deletedIds[] = $video->id;
                    event(new VideoUpdated($video->getAttributes(), []));
                }

                continue;
            }

            $video->video_downloaded_num = 0;
            $video->video_downloaded_at = null;
            $video->save();

            if ($requeueAfterDelete) {
                if ($video->trashed()) {
                    $video->restore();
                }
                $this->enqueueVideoDownloadTasks($video);
            }
            $deletedIds[] = $video->id;
            event(new VideoUpdated($video->getAttributes(), []));
        }

        Log::info('Delete videos completed', [
            'ids' => $ids,
            'deleted_ids' => $deletedIds,
            'permanent' => $permanentDelete,
            'requeue' => $requeueAfterDelete,
        ]);

        return $deletedIds;
    }

    private function removeVideoFilesAndResetState(Video $video): void
    {
        $video->parts->each(function (VideoPart $videoPart) {
            app(DownloadVideoService::class)->deleteVideoPartFile($videoPart);
            $videoPart->video_download_path = null;
            $videoPart->video_downloaded_at = null;
            $videoPart->save();
        });

        if ($video->audioPart) {
            $audioPart = $video->audioPart;
            if ($audioPart->audio_download_path) {
                $relativePath = Str::startsWith($audioPart->audio_download_path, '/storage/')
                    ? Str::after($audioPart->audio_download_path, '/storage/')
                    : ltrim($audioPart->audio_download_path, '/');
                Storage::disk('public')->delete($relativePath);
            }
            $audioPart->audio_download_path = null;
            $audioPart->audio_downloaded_at = null;
            $audioPart->save();
        }
    }

    /**
     * 彻底清理视频的全部关联数据（供永久删除 / 孤儿清理使用）。
     *
     * 兼容性说明：danmaku / video_parts / 两个 pivot 表均无外键约束，
     * 旧版本删除视频后会残留孤儿数据；这里按 video_id（含新索引）与 cid
     * 逐一清理，不会波及其他视频的数据。
     */
    public function purgeVideoData(Video $video): void
    {
        // 1. 分 P 的 cid（弹幕文件以 cid 为键）
        $cids = VideoPart::query()
            ->where('video_id', $video->id)
            ->pluck('cid')
            ->map(fn ($cid) => (int) $cid)
            ->all();

        // 2. 弹幕：文件 + 索引 + 数据库行（历史行按 video_id / cid 双路清理）
        app(DanmakuFileStore::class)->deleteMany($cids);
        Danmaku::query()->where('video_id', $video->id)->delete();
        if (! empty($cids)) {
            Danmaku::query()->whereIn('cid', $cids)->delete();
        }
        DanmakuFile::query()->where('video_id', $video->id)->delete();

        // 3. 评论（大表按 video_id 索引删除，避免孤儿评论堆积）
        Comment::query()->where('video_id', $video->id)->delete();

        // 4. 分 P / 音频记录
        VideoPart::query()->where('video_id', $video->id)->delete();
        AudioPart::query()->where('video_id', $video->id)->delete();

        // 5. 收藏夹 / 订阅关联（pivot 表无外键，必须手动清理）
        DB::table('favorite_list_videos')->where('video_id', $video->id)->delete();
        DB::table('subscription_videos')->where('video_id', $video->id)->delete();

        // 6. 封面多态关联（covers 本体可能被多处共享，不做删除）
        Coverables::query()
            ->where('coverable_id', $video->id)
            ->where('coverable_type', Video::class)
            ->delete();
    }

    private function enqueueVideoDownloadTasks(Video $video): void
    {
        /** @var DownloadQueueService $queueService */
        $queueService = app(DownloadQueueService::class);

        if ($video->isAudio() && $video->audioPart) {
            $queueService->enqueueAudio($video->audioPart);

            return;
        }

        foreach ($video->parts as $videoPart) {
            $queueService->enqueueVideo($videoPart);
        }
    }

    public function getFavVideosLightweight(int $favId): array
    {
        $cacheKey = "fav_videos:{$favId}";
        $cached = redis()->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        $videos = $this->queryFavVideosLightweight($favId);

        redis()->set($cacheKey, json_encode($videos));
        redis()->expire($cacheKey, 600);

        return $videos;
    }

    public function getSubVideosLightweight(int $subId): array
    {
        $cacheKey = "sub_videos:{$subId}";
        $cached = redis()->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        $videos = $this->querySubVideosLightweight($subId);

        redis()->set($cacheKey, json_encode($videos));
        redis()->expire($cacheKey, 600);

        return $videos;
    }

    private function queryFavVideosLightweight(int $favId): array
    {
        $rows = DB::table('videos')
            ->join('favorite_list_videos', 'videos.id', '=', 'favorite_list_videos.video_id')
            ->leftJoin('coverables', function ($join) {
                $join->on('coverables.coverable_id', '=', 'videos.id')
                    ->where('coverables.coverable_type', '=', Video::class);
            })
            ->leftJoin('covers', 'covers.id', '=', 'coverables.cover_id')
            ->where('favorite_list_videos.favorite_list_id', $favId)
            ->whereNull('videos.deleted_at') // 【修复】：过滤软删除的数据
            ->select([
                'videos.id', 'videos.title', 'videos.bvid',
                'videos.pubtime', 'videos.fav_time', 'videos.page',
                'videos.video_downloaded_num', 'videos.audio_downloaded_num',
                'videos.frozen', 'videos.invalid', 'videos.cover',
                'videos.created_at', 'videos.duration', 'videos.view', // 【修复】：补充时长和播放量
                'covers.path as cover_path',
            ])
            ->orderByDesc('videos.fav_time')
            ->orderByDesc('videos.created_at')
            ->get();

        return $rows->map(fn ($row) => $this->transformLightweightVideo($row))->toArray();
    }

    private function querySubVideosLightweight(int $subId): array
    {
        $rows = DB::table('videos')
            ->join('subscription_videos', 'videos.id', '=', 'subscription_videos.video_id')
            ->leftJoin('coverables', function ($join) {
                $join->on('coverables.coverable_id', '=', 'videos.id')
                    ->where('coverables.coverable_type', '=', Video::class);
            })
            ->leftJoin('covers', 'covers.id', '=', 'coverables.cover_id')
            ->where('subscription_videos.subscription_id', $subId)
            ->whereNull('videos.deleted_at') // 【修复】：过滤软删除的数据
            ->select([
                'videos.id', 'videos.title', 'videos.bvid',
                'videos.pubtime', 'videos.fav_time', 'videos.page',
                'videos.video_downloaded_num', 'videos.audio_downloaded_num',
                'videos.frozen', 'videos.invalid', 'videos.cover',
                'videos.created_at', 'videos.duration', 'videos.view', // 【修复】：补充时长和播放量
                'covers.path as cover_path',
            ])
            ->orderByDesc('videos.pubtime')
            ->orderByDesc('videos.created_at')
            ->get();

        return $rows->map(fn ($row) => $this->transformLightweightVideo($row))->toArray();
    }

    private function transformLightweightVideo(object $row): array
    {
        $item = (array) $row;
        $item['cover_image_url'] = $item['cover_path']
            ? Storage::url($item['cover_path'])
            : null;
        unset($item['cover_path']);
        $item['pubtime'] = $item['pubtime'] ? strtotime($item['pubtime']) : null;
        $item['fav_time'] = $item['fav_time'] ? strtotime($item['fav_time']) : null;

        return $item;
    }

    private function getAllVideosLightweight(): array
    {
        // 【核心优化】单条 JOIN 查询替代逐行取 cover_info 的 N+1 实现：
        // 旧实现对 2.4 万个视频会触发约 4.8 万次 SQL（每视频 2 次封面查询），
        // 每次缓存全量重建（视频更新后 20 秒防抖）都重复付出该代价。
        // 输出结构与旧实现完全一致，前端无需任何改动。
        $rows = DB::table('videos')
            ->leftJoin('coverables', function ($join) {
                $join->on('coverables.coverable_id', '=', 'videos.id')
                    ->where('coverables.coverable_type', '=', Video::class);
            })
            ->leftJoin('covers', 'covers.id', '=', 'coverables.cover_id')
            ->whereNull('videos.deleted_at')
            ->select([
                'videos.id', 'videos.title', 'videos.bvid',
                'videos.pubtime', 'videos.fav_time', 'videos.page',
                'videos.video_downloaded_num', 'videos.audio_downloaded_num',
                'videos.frozen', 'videos.invalid', 'videos.cover',
                'videos.created_at', 'videos.duration', 'videos.view',
                'covers.path as cover_path',
            ])
            ->orderByRaw('COALESCE(videos.fav_time, videos.created_at) DESC')
            ->get();

        return $rows->map(fn ($row) => $this->transformLightweightVideo($row))->toArray();
    }

    public function updateVideosCache(?array $videos = null): void
    {
        if ($videos && is_array($videos) && count($videos) > 0) {
            $list = $videos;
        } else {
            $list = $this->getAllVideosLightweight();
        }
        redis()->set('video_list', json_encode($list), ['EX' => $this->ttl]);
        Log::info('Update videos cache success', ['count' => count($list)]);
    }

    public function getVideosCache(): array
    {
        $list = redis()->get('video_list');
        if ($list) {
            return json_decode($list, true);
        }
        $list = $this->getAllVideosLightweight();
        $this->updateVideosCache($list);

        return $list;
    }
}
