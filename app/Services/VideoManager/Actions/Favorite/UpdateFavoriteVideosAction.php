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

        // // 【安全修复1】：只有在开启新一轮同步（拉取第 1 页）时，才清空旧缓存。
        // // 这样可以彻底斩断过去的幽灵数据，同时在多页拉取时能安全累加。
        // if ($currentPage === 1) {
        //     redis()->del(sprintf('favorite_video_saving:%s', $favId));
        //     Log::info('[收藏夹管理] 开启新一轮同步，已清空旧的 Redis 暂存数据', ['favId' => $favId]);
        // }

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

            // 如果本地存在且已经无效，且远程无效，跳过更新
            if($exist && $exist->invalid && $videoInvalid) {
                return null;
            }

            // 如果本地已经冻结，且远程也无效， 则跳过
            if($exist && ($exist->video_downloaded_num > 0 || $exist->audio_downloaded_num > 0) && $videoInvalid) {
                return null;
            }

            // 是否冻结该视频: 是否已经保护备份了该视频
            // 如果已经冻结了该视频, 就不更新该视频的主要信息
            // 2026/03/29 变为：只有本地下载了才认为是冻结，封面标题可修复不算冻结
            $frozen          = $exist && $videoInvalid && ($exist->video_downloaded_num > 0 || $exist->audio_downloaded_num > 0);
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
        // 【核心大扫除逻辑 - 完美异步并发安全版】
        // =========================================================
        $pageSize = 40; 
        $expectedPages = (int) ceil(intval($fav['media_count']) / $pageSize);
        
        // 1. 使用 Redis 集合记录当前收藏夹已完成拉取的页码
        $pageTrackKey = sprintf('fav_synced_pages:%s', $favId);
        redis()->sadd($pageTrackKey, $currentPage);
        redis()->expire($pageTrackKey, 86400); // 设置一天过期，防止遗留垃圾数据

        // 2. 获取目前已成功暂存到 Redis 的页数
        $syncedPagesCount = redis()->scard($pageTrackKey);

        Log::info('[收藏夹管理] 页面同步进度', [
            'favId'          => $favId,
            'current_page'   => $currentPage,
            'synced_pages'   => $syncedPagesCount,
            'expected_pages' => $expectedPages
        ]);

        // 3. 只有当 所有分页 的任务全部执行完毕，才具备大扫除资格！
        // 如果中间有任何一页因为网络失败报错，条件都不成立，直接中断保护数据。
        if ($syncedPagesCount >= $expectedPages) {
            $savedVideos = $this->getFavoriteVideo($favId);
            
            Log::info('[收藏夹管理] 所有分页已全部拉取完毕，开始执行全量视频大扫除校验', [
                'favId'            => $favId, 
                'favTitle'         => $fav['title'],
                'media_count'      => intval($fav['media_count']), 
                'savedVideosCount' => count($savedVideos)
            ]);

            if (count($savedVideos) > 0) {
                $remoteCacheVideoIds = array_column($savedVideos, 'id');
                // 此时 Redis 里的 savedVideos 是所有并发任务完美拼凑出的全量数据
                $deleteVideoIds = array_diff($localVideoIds, $remoteCacheVideoIds);
                
                if (! empty($deleteVideoIds)) {
                    Log::info('[收藏夹管理] 检测到视频已被移出或彻底取消收藏，准备同步解绑', [
                        'favId'          => $favId, 
                        '解绑的视频数量' => count($deleteVideoIds),
                        '解绑列表IDs'    => array_values($deleteVideoIds)
                    ]);

                    \App\Models\FavoriteList::query()->where('id', $favId)->first()->videos()->detach($deleteVideoIds);
                    Log::info('[收藏夹管理] ============ 解绑清理执行完毕 ============', ['favId' => $favId]);
                } else {
                    Log::info('[收藏夹管理] 远端列表与本地一致，无需解绑', ['favId' => $favId]);
                }
            }

            // 4. 大扫除完成后，彻底清理掉页码追踪和视频暂存缓存，为下一次完美同步铺路
            redis()->del($pageTrackKey);
            redis()->del(sprintf('favorite_video_saving:%s', $favId));
            
        } else {
            // 保护机制：任何未凑齐所有页面的任务（包含日常的单页增量更新），绝不执行删除！
            Log::info('[收藏夹管理] 并发任务未全部完成或为增量同步，跳过大扫除', [
                'favId'          => $favId,
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
