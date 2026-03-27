<?php
namespace App\Services\VideoManager\Actions\Favorite;

use App\Events\UpperTryUpdated;
use App\Events\VideoUpdated;
use App\Models\FavoriteList;
use App\Models\Video;
use App\Services\BilibiliService;
use App\Services\VideoManager\Traits\CacheableTrait;
use App\Services\VideoManager\Traits\VideoDataTrait;
use Arr;
use DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\DownloadCommentsJob; // 引入评论下载任务
use App\Jobs\DownloadVideoTagsJob; // 引入标签下载任务

class UpdateFavoriteVideosAction
{
    use CacheableTrait, VideoDataTrait;

    public function __construct(
        public BilibiliService $bilibiliService,
    ) {
    }

    /**
     * 更新收藏夹视频列表
     */
    public function execute(array $fav, ?int $page = null): void
    {
        $favId  = $fav['id'];
        $currentPage = $page ?: 1; // 统一处理 null 为 1

        // 【安全修复1】：只有在开启新一轮同步（拉取第 1 页）时，才清空旧缓存。
        // 这样可以彻底斩断过去的幽灵数据，同时在多页拉取时能安全累加。
        if ($currentPage === 1) {
            redis()->del(sprintf('favorite_video_saving:%s', $favId));
            Log::info('[收藏夹管理] 开启新一轮同步，已清空旧的 Redis 暂存数据', ['favId' => $favId]);
        }

        $videos = $this->bilibiliService->pullFavVideoList($favId, $page);

        if (count($videos) === 0) {
            Log::info('[收藏夹管理] 未获取到视频数据', ['favId' => $favId]);
            // 特殊情况：如果第一页就为空，且B站返回总数也是0，说明用户清空了整个收藏夹
            if ($currentPage === 1 && intval($fav['media_count']) === 0) {
                $localVideoIds = DB::table('favorite_list_videos')->where('favorite_list_id', $favId)->pluck('video_id')->toArray();
                if (!empty($localVideoIds)) {
                    FavoriteList::query()->where('id', $favId)->first()->videos()->detach($localVideoIds);
                    Log::info('[收藏夹管理] 收藏夹已被完全清空，同步解绑所有视频', ['favId' => $favId]);
                }
            }
            return;
        }

        $videos = array_map(function ($item) {
            $videoInvalid = $this->videoIsInvalid($item);

            $exist = Video::withTrashed()->where('id', $item['id'])->first();

            // 如果视频已经删除即忽略
            if ($exist && $exist->trashed()) {
                return null;
            }

            // 是否冻结该视频: 是否已经保护备份了该视频
            // 如果已经冻结了该视频, 就不更新该视频的主要信息
            $frozen          = $exist && $exist['title'] !== '已失效视频' && $videoInvalid;
            $item['frozen']  = $frozen;
            $item['invalid'] = $videoInvalid;

            if ($frozen) {
                Log::info('[收藏夹管理] 视频已冻结', ['id' => $item['id'], 'title' => $exist['title']]);
                $item     = array_merge($exist->toArray(), Arr::except($item, ['attr', 'title', 'cover', 'intro']));
                $newValue = $item;
            } else {
                $newValue = $item;
            }

            $upperId = $newValue['upper']['mid'] ?? ($exist['upper_id'] ?? null);

            if ($newValue['upper']) {
                event(new UpperTryUpdated($newValue['upper']));
            }

            //在此做键值对映射，避免字段未来变更
            return [
                'id'       => $item['id'],
                'link'     => $newValue['link'],
                'title'    => $newValue['title'],
                'intro'    => $newValue['intro'],
                'cover'    => $newValue['cover'],
                'bvid'     => $newValue['bvid'],
                'pubtime'  => date('Y-m-d H:i:s', $newValue['pubtime']),
                'duration' => $newValue['duration'],
                'view'     => $newValue['cnt_info']['play'] ?? $newValue['stat']['view'] ?? 0, // 播放量数据
                'attr'     => $newValue['attr'],
                'invalid'  => $newValue['invalid'],
                'frozen'   => $newValue['frozen'],
                'page'     => $newValue['page'],
                'fav_time' => date('Y-m-d H:i:s', $newValue['fav_time']),
                'upper_id' => $upperId,
                'type'     => $newValue['type'],
            ];
        }, $videos);

        $videos = array_filter($videos);
        if (empty($videos)) {
            return;
        }

        // 暂存视频数据
        $this->saveFavoriteVideo($favId, $videos);

        $remoteVideoIds = array_column($videos, 'id');
        $localVideoIds  = DB::table('favorite_list_videos')
            ->where('favorite_list_id', $favId)
            ->pluck('video_id')
            ->toArray();

        $addVideoIds = array_diff($remoteVideoIds, $localVideoIds);

        // 添加新的视频关联关系
        if (! empty($addVideoIds)) {
            $attachData = [];
            foreach ($videos as $video) {
                if (in_array($video['id'], $addVideoIds)) {
                    $attachData[$video['id']] = [
                        'created_at' => $video['fav_time'],
                        'updated_at' => $video['fav_time'],
                    ];
                }
            }
            if (! empty($attachData)) {
                $favoriteList = FavoriteList::query()->where('id', $favId)->first();
                $favoriteList->videos()->attach($attachData);
                Log::info('[收藏夹管理] 关联新视频到收藏夹', ['favId' => $favId, 'videoIds' => array_keys($attachData)]);
            }
        }

        foreach ($videos as $key => $item) {
            $video = Video::query()->firstOrNew(['id' => $item['id']]);
            $oldVideoData = $video->getAttributes();
            
            // 检查视频数据是否真正发生了变化
            $hasChanges    = false;
            $changedFields = [];

            foreach ($item as $field => $value) {
                if (! isset($oldVideoData[$field]) || $oldVideoData[$field] != $value) {
                    $hasChanges            = true;
                    $changedFields[$field] = [
                        'old' => $oldVideoData[$field] ?? null,
                        'new' => $value,
                    ];
                }
            }

            $video->fill($item);
            $video->save();

            // 只有在数据真正发生变化时才触发事件
            if ($hasChanges) {
                Log::info('[收藏夹管理] 视频数据发生变化, 触发 VideoUpdated 事件', [
                    'id'             => $item['id'],
                    'title'          => $item['title'],
                    'changed_fields' => array_keys($changedFields),
                ]);
                event(new VideoUpdated($oldVideoData, $video->getAttributes()));
            } else {
                Log::info('[收藏夹管理] 视频数据未变化, 跳过 VideoUpdated 事件', [
                    'id'    => $item['id'],
                    'title' => $item['title'],
                ]);
            }
            
            // 【新增】无论视频是否更新，只要有效，都尝试下载/更新评论
            // 这样可以确保旧视频也能补全评论
            // if ($video->invalid == 0) {
            //      dispatch(new \App\Jobs\DownloadCommentsJob($video));
            // }
            // [修改] 仅当视频是“新创建”的时候，才自动下载一次评论
            // 存量视频的评论更新交由 app:download-all-comments 命令或专门的定时任务去处理
            if ($video->wasRecentlyCreated && $video->invalid == 0) {
                 Log::info('[收藏夹管理] 检测到新视频, 正在派发初始评论下载任务', ['id' => $video->id]);
                 dispatch(new DownloadCommentsJob($video));
            }

            Log::info('[收藏夹管理] 视频信息更新成功', ['id' => $item['id'], 'title' => $item['title']]);
        }

        // =========================================================
        // 【核心大扫除逻辑 - 安全拦截版】
        // =========================================================
        $pageSize = 40; // B站 API 每页数量
        $isLastPage = false;
        
        // 判定当前是否为该收藏夹的“最后一页”
        if (count($videos) < $pageSize) {
            // 如果本页获取的视频数不足 40，毫无疑问这是最后一页
            $isLastPage = true;
        } elseif (($currentPage * $pageSize) >= (intval($fav['media_count']) - 10)) {
            // 如果当前页码算出的总量已经涵盖了 media_count（容忍10个失效视频误差），也是最后一页
            $isLastPage = true;
        }

        if ($isLastPage) {
            $savedVideos = $this->getFavoriteVideo($favId);
            Log::info('[收藏夹管理] 最后一页拉取完毕，开始执行全量视频大扫除校验', [
                'favId'            => $favId, 
                'favTitle'         => $fav['title'],
                'media_count'      => intval($fav['media_count']), 
                'savedVideosCount' => count($savedVideos)
            ]);

            if (count($savedVideos) > 0) {
                $remoteCacheVideoIds = array_column($savedVideos, 'id');
                // 因为是最后一页，Redis 里的 savedVideos 已经是该收藏夹所有干净的存活视频，可以安全取差集
                $deleteVideoIds = array_diff($localVideoIds, $remoteCacheVideoIds);
                
                if (! empty($deleteVideoIds)) {
                    Log::info('[收藏夹管理] 检测到视频已被移出或彻底取消收藏，准备同步解绑', [
                        'favId'          => $favId, 
                        '解绑的视频数量' => count($deleteVideoIds),
                        '解绑列表IDs'    => array_values($deleteVideoIds)
                    ]);

                    FavoriteList::query()->where('id', $favId)->first()->videos()->detach($deleteVideoIds);
                    Log::info('[收藏夹管理] ============ 解绑清理执行完毕 ============', ['favId' => $favId]);
                } else {
                    Log::info('[收藏夹管理] 远端列表与本地一致，无需解绑', ['favId' => $favId]);
                }
            }
        } else {
            // 【保护机制】：定时任务如果只拉取了第一页，绝不能执行清理操作！
            Log::info('[收藏夹管理] 当前为增量分页同步，为保护后续页数据，跳过大扫除逻辑', [
                'favId'       => $favId,
                'currentPage' => $currentPage
            ]);
        }
    }

    public function getFavoriteVideo(int $favoriteId): array
    {
        $videos = redis()->get(sprintf('favorite_video_saving:%s', $favoriteId));
        if ($videos === null) {
            return [];
        }
        $decoded = json_decode($videos, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveFavoriteVideo(int $favoriteId, array $videos): void
    {
        $existVideos = $this->getFavoriteVideo($favoriteId);
        if (is_array($existVideos) && count($existVideos) > 0) {
            // 使用id作为键进行去重合并，新数据会覆盖旧数据
            $mergedVideos = [];

            // 先添加已存在的视频
            foreach ($existVideos as $video) {
                if (isset($video['id'])) {
                    $mergedVideos[$video['id']] = $video;
                }
            }

            // 再添加新视频，会覆盖相同id的旧数据
            foreach ($videos as $video) {
                if (isset($video['id'])) {
                    $mergedVideos[$video['id']] = $video;
                }
            }

            $videos = array_values($mergedVideos);
        }
        redis()->set(sprintf('favorite_video_saving:%s', $favoriteId), json_encode($videos));
    }

}
