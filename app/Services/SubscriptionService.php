<?php
namespace App\Services;

use App\Events\SubscriptionUpdated;
use App\Events\VideoUpdated;
use App\Jobs\PullVideoInfoJob;
use App\Jobs\UpdateSubscriptionJob;
use App\Models\Subscription;
use App\Models\SubscriptionVideo;
use App\Models\Video;
use App\Services\BilibiliService;
use App\Services\VideoManager\Contracts\VideoServiceInterface;
use Http;
use Log;

class SubscriptionService
{
    public function __construct(public BilibiliService $bilibiliService, public VideoServiceInterface $videoService)
    {
    }

    public function deleteSubscription(Subscription $subscription)
    {
        // 预加载关联数据，避免 N+1 查询
        $subscription->load(['videos.favorite', 'videos.subscriptions']);
        
        $removeIds = [];
        foreach($subscription->videos as $video) {
            // 判断视频是否被收藏夹引用
            if($video->favorite->count() > 0){
                Log::info('video is referenced by favorite', [
                    'video_id' => $video->id, 
                    'subscription_id' => $subscription->id, 
                    'title' => $video->title
                ]);
                continue;
            }

            // 判断视频是否被其他订阅引用（大于1表示除了当前订阅外还有其他订阅）
            if($video->subscriptions->count() > 1){
                Log::info('video is referenced by other subscription', [
                    'video_id' => $video->id, 
                    'subscription_id' => $subscription->id,
                    'subscriptions_count' => $video->subscriptions->count(),
                    'title' => $video->title
                ]);
                continue;
            }
            
            // 只有当视频没有被任何收藏夹或其他订阅引用时，才加入删除列表
            $removeIds[] = $video->id;
        }
        
        // 删除未被引用的视频
        if (!empty($removeIds)) {
            $this->videoService->deleteVideos($removeIds);
        }
        
        // 删除订阅与视频的关联关系
        SubscriptionVideo::where('subscription_id', $subscription->id)->delete();
        
        // 删除订阅与封面的关联关系（不删除封面本身）
        $subscription->coverImage()->detach();
        
        // 删除订阅记录
        $subscription->delete();
    }


    /**
     * 获取重定向后的URL
     */
    public function getRedirectURL($url)
    {
        $response = Http::get($url);
        return empty($response->effectiveUri()->__toString()) ? $url : $response->effectiveUri()->__toString();
    }

    public function addSubscription($type, $url)
    {
        if(preg_match("#https://b23.tv#", $url)){
            $url = $this->getRedirectURL($url);
        }

        // [新增] 订阅类型 收藏夹 逻辑
        if ($type == 'favorite') {
            $mediaId = null;
            // 1. 尝试从 URL 中提取 fid (https://space.bilibili.com/xxx/favlist?fid=123)
            if (preg_match('/fid=(\d+)/', $url, $matches)) {
                $mediaId = $matches[1];
            } 
            // 2. 尝试提取 ml id (https://www.bilibili.com/medialist/detail/ml123)
            elseif (preg_match('/ml(\d+)/', $url, $matches)) {
                $mediaId = $matches[1];
            } 
            // 3. 支持直接输入纯数字 ID
            elseif (is_numeric($url)) {
                $mediaId = $url;
            }

            if (!$mediaId) {
                throw new \Exception('invalid favorite url or id');
            }

            // 获取收藏夹标题和封面 (需要 BilibiliService 支持 getFavoriteFolderInfo)
            $info = $this->bilibiliService->getFavoriteFolderInfo($mediaId);
            if (empty($info)) {
                throw new \Exception('无法获取收藏夹信息，请确认ID正确且公开');
            }

            $subscription           = Subscription::query()->where('media_id', $mediaId)->firstOrNew();
            $subscription->type     = 'favorite';
            $subscription->media_id = $mediaId;
            $subscription->mid      = $info['mid'];
            $subscription->name     = $info['title']; // 使用收藏夹标题作为订阅名
            $subscription->cover    = $info['cover'] ?? '';
            $subscription->url      = $url;
            // 如果表中有 upper_id 字段，建议存入创建者 mid
            if (isset($subscription->upper_id) && isset($info['upper']['mid'])) {
                $subscription->upper_id = $info['upper']['mid'];
            }
            $subscription->save();

            UpdateSubscriptionJob::dispatch($subscription, true);
            return $subscription;
        }
        
        if ($type == 'seasons') {
            if (! preg_match('#/(\d+)/lists/(\d+)#', $url, $matches)) {
                throw new \Exception('invalid seasons url');
            }
            $mid                   = $matches[1];
            $seasonId              = $matches[2];
            $subscription          = Subscription::query()->where('mid', $mid)->where('list_id', $seasonId)->firstOrNew();
            $subscription->type    = $type;
            $subscription->mid     = $mid;
            $subscription->list_id = $seasonId;
            $subscription->url     = $url;
            $subscription->save();

            UpdateSubscriptionJob::dispatch($subscription, true);
            return $subscription;
        } else if ($type == 'series') {
            if (! preg_match('#/(\d+)/lists/(\d+)#', $url, $matches)) {
                throw new \Exception('invalid series url');
            }
            $mid                   = $matches[1];
            $seriesId              = $matches[2];
            $subscription          = Subscription::query()->where('mid', $mid)->where('list_id', $seriesId)->firstOrNew();
            $subscription->type    = $type;
            $subscription->mid     = $mid;
            $subscription->list_id = $seriesId;
            $subscription->url     = $url;
            $subscription->save();
            UpdateSubscriptionJob::dispatch($subscription, true);
            return $subscription;
        } else {
            // 订阅类型UP主逻辑
            if (! preg_match('#/(\d+)/upload#', $url, $matches) && !preg_match('#https://space.bilibili.com/(\d+)#', $url, $matches)) {
                throw new \Exception('invalid up url');
            }
            $mid                = $matches[1];
            $subscription       = Subscription::query()->where('mid', $mid)->where('type', 'up')->firstOrNew();
            $subscription->type = 'up';
            $subscription->mid  = $mid;
            $subscription->url  = $url;
            $subscription->save();
            UpdateSubscriptionJob::dispatch($subscription, true);
            return $subscription;
        }
    }

    public function getSubscriptions()
    {
        return Subscription::query()->get();
    }

    public function disableSubscription(Subscription $subscription)
    {
        $subscription->status = Subscription::STATUS_DISABLED;
        $subscription->save();
    }

    public function enableSubscription(Subscription $subscription)
    {
        $subscription->status = Subscription::STATUS_ACTIVE;
        $subscription->save();
    }

    public function changeSubscription(Subscription $subscription, array $data)
    {
        $subscription->fill($data);
        $subscription->save();
        return $subscription;
    }

    public function updateSubscriptions()
    {
        $subscriptions = Subscription::where('status', Subscription::STATUS_ACTIVE)->where('last_check_at', '<', now()->subMinutes(20))->get();
        foreach ($subscriptions as $subscription) {
            $this->updateSubscription($subscription, false);
        }
    }

    public function updateSubscription(Subscription $subscription, bool $pullAll = false)
    {
        if ($subscription->type == 'seasons' || $subscription->type == 'series') {
            if (! $this->lockSubscription($subscription->mid, $subscription->list_id)) {
                return;
            }
            try {
                $this->updateSeasonsAndSeries($subscription->type, $subscription->mid, $subscription->list_id, $pullAll);
            } finally {
                $this->unlockSubscription($subscription->mid, $subscription->list_id);
            }
        } 
        // [新增] 收藏夹更新调度
        else if ($subscription->type == 'favorite') {
            // 使用 media_id 作为锁的标识
            if (! $this->lockSubscription('favorite', $subscription->media_id)) {
                return;
            }
            try {
                $this->updateFavoriteVideos($subscription, $pullAll);
            } finally {
                $this->unlockSubscription('favorite', $subscription->media_id);
            }
        }
        else if ($subscription->type == 'up') {
            if (! $this->lockSubscription($subscription->mid)) {
                return;
            }
            try {
                $this->updateUpVideos($subscription->mid, $pullAll);
            } finally {
                $this->unlockSubscription($subscription->mid);
            }
        }
    }

    public function unlockSubscription($mid, $listId = null)
    {
        redis()->del("subscription:lock:{$mid}:{$listId}");
    }

    protected function lockSubscription($mid, $listId = null)
    {
        $lock = redis()->setnx("subscription:lock:{$mid}:{$listId}", 1);
        if (! $lock) {
            return false;
        }
        redis()->expire("subscription:lock:{$mid}:{$listId}", 1200);
        return true;
    }

    public function updateSeasonsAndSeries($type, $mid, $listId, $pullAll = false)
    {
        if (! in_array($type, ['seasons', 'series'])) {
            throw new \Exception('invalid type');
        }
        $subscription          = Subscription::query()->where('mid', $mid)->where('list_id', $listId)->firstOrNew();
        $subscription->type    = $type;
        $subscription->mid     = $mid;
        $subscription->list_id = $listId;
        $subscription->save();

        if ($type == "series") {
            $dataMeta                  = $this->bilibiliService->getSeriesMeta($listId);
            $listMeta                  = $dataMeta['meta'];
            $subscription->total       = $listMeta['total'];
            $subscription->name        = $listMeta['name'];
            $subscription->description = $listMeta['description'];
            $subscription->cover       = $listMeta['cover'] ?? '';
            $subscription->save();
        }

        $oldSubscription = $subscription->toArray();
        $page            = 1;
        $loaded          = 0;
        while (1) {
            if ($type == 'seasons') {
                $dataList = $this->bilibiliService->getSeasonsList($mid, $listId, $page);
            } else {
                $dataList = $this->bilibiliService->getSeriesList($mid, $listId, $page);
            }
            if (is_array($dataList) && isset($dataList['archives']) && is_array($dataList['archives'])) {

                if (count($dataList['archives']) == 0) {
                    break;
                }

                $loaded += count($dataList['archives']);
                if ($type == "seasons" && isset($dataList['meta'])) {
                    $listMeta                  = $dataList['meta'];
                    $subscription->total       = $listMeta['total'];
                    $subscription->name        = $listMeta['name'];
                    $subscription->description = $listMeta['description'];
                    $subscription->cover       = $listMeta['cover'];
                    $subscription->save();
                }

                if ($type == "series" && $page == 1 && count($dataList['archives']) > 0) {
                    $subscription->cover = $dataList['archives'][0]['pic'] ?? '';
                    $subscription->save();
                }

                $archives = $dataList['archives'];
                foreach ($archives as $archive) {
                    $subscriptionVideo                  = SubscriptionVideo::where('subscription_id', $subscription->id)->where('video_id', $archive['aid'])->firstOrNew();
                    $subscriptionVideo->bvid            = $archive['bvid'];
                    $subscriptionVideo->subscription_id = $subscription->id;
                    $subscriptionVideo->video_id        = $archive['aid'];
                    $subscriptionVideo->save();

                    PullVideoInfoJob::dispatchWithRateLimit($archive['bvid']);
                }

                if ($loaded >= $subscription->total) {
                    break;
                }

                if (! $pullAll) {
                    break;
                }

                $page++;
            }
        }
        $subscription->last_check_at = now();
        $subscription->save();

        event(new SubscriptionUpdated($oldSubscription, $subscription->toArray()));
        return $subscription;
    }

    public function updateUpVideos($mid, $pullAll = false)
    {
        $subscription       = Subscription::where('mid', $mid)->where('type', 'up')->firstOrNew();
        $subscription->type = 'up';
        $subscription->mid  = $mid;
        $subscription->save();
        $oldSubscription = $subscription->toArray();

        $uperCard                  = $this->bilibiliService->getUperCard($mid);
        $subscription->name        = $uperCard['name'] ?? '';
        $subscription->cover       = $uperCard['face'] ?? '';
        $subscription->description = $uperCard['sign'] ?? '';
        $subscription->save();

        $offsetAid = null;
        $loaded    = 0;
        while (1) {
            Log::info('get up videos', ['offsetAid' => $offsetAid, 'loaded' => $loaded]);
            while (1) {
                $retry = 0;
                try {
                    $upVideos = $this->bilibiliService->getUpVideos($mid, $offsetAid);
                } catch (\Exception $e) {
                    Log::error('get up videos error', ['error' => $e->getMessage()]);
                    $retry++;
                    if ($retry > 3) {
                        Log::error('get up videos error: ' . $e->getMessage());
                        throw new \Exception('get up videos error: ' . $e->getMessage());
                    }
                    continue;
                }
                break;
            }
            foreach ($upVideos['list'] as $item) {
                Log::info('up video', ['title' => $item['title']]);
                $aid                                = $item['param'];
                $subscriptionVideo                  = SubscriptionVideo::where('subscription_id', $subscription->id)->where('video_id', $aid)->firstOrNew();
                $subscriptionVideo->bvid            = $item['bvid'];
                $subscriptionVideo->subscription_id = $subscription->id;
                $subscriptionVideo->video_id        = $aid;
                $subscriptionVideo->save();

                // 快速填写一个视频信息
                // 这里获取到的视频都是有效的，所以可以忽略 invalid 处理和封面判断
                $video = Video::withTrashed()->where('id', $aid)->firstOrNew();
                $video->fill([
                    'id'       => $aid,
                    'upper_id' => $mid,
                    'bvid'     => $item['bvid'],
                    'title'    => $item['title'],
                    'cover'    => $item['cover'],
                    'duration' => $item['duration'],
                    'page'     => intval($item['videos']),
                    'pubtime'  => date('Y-m-d H:i:s', $item['ctime']),
                    'link'     => sprintf('https://www.bilibili.com/video/%s', $item['bvid']),
                    'intro'    => '',
                ]);
                $video->save();
                if($video->trashed()){
                    $video->restore();
                }
                event(new VideoUpdated([], $video->getAttributes()));

                PullVideoInfoJob::dispatchWithRateLimit($item['bvid']);
            }
            $loaded += count($upVideos['list']);

            if (! $pullAll) {
                break;
            }

            if (count($upVideos['list']) == 0) {
                break;
            }

            if (! $upVideos['has_next']) {
                break;
            }
            $offsetAid = $upVideos['last_aid'];
        }
        $subscription->total         = $loaded;
        $subscription->last_check_at = now();
        $subscription->save();
        event(new SubscriptionUpdated($oldSubscription, $subscription->toArray()));
        return $subscription;
    }

    /**
     * 更新收藏夹订阅 (参考 updateUpVideos 风格)
     */
    protected function updateFavoriteVideos(Subscription $subscription, bool $pullAll = false)
    {
        // 1. 记录更新前的状态，用于最后触发事件对比
        $oldSubscription = $subscription->toArray();
        $loaded = 0;
        $page = 1;

        while (true) {
            // 调用 BilibiliService 获取列表
            // 第二个参数传 $page，明确控制分页
            $videos = $this->bilibiliService->pullFavVideoList((int)$subscription->media_id, $page);

            // 如果结果为空，直接跳出
            if (empty($videos)) {
                break;
            }

            foreach ($videos as $item) {
                $aid = $item['id'];
                
                // 查找或新建视频
                $video = Video::query()->where('id', $aid)->firstOrNew();
                
                // 填充视频数据
                $video->fill([
                    'id'       => $aid,
                    'upper_id' => $item['upper']['mid'] ?? 0,
                    'bvid'     => $item['bvid'],
                    'title'    => $item['title'],
                    'cover'    => $item['cover'],
                    'duration' => $item['duration'] ?? 0,
                    'pubtime'  => date('Y-m-d H:i:s', $item['ctime'] ?? time()),
                    'link'     => "https://www.bilibili.com/video/" . $item['bvid'],
                    'intro'    => $item['intro'] ?? '',
                ]);
                $video->save();

                // 如果视频被软删除了，恢复它
                if ($video->trashed()) {
                    $video->restore();
                }

                // 触发视频更新事件
                event(new VideoUpdated([], $video->getAttributes()));

                // 【重要】绑定订阅关系 (写入 subscription_videos 中间表)
                // 使用 firstOrCreate 防止重复插入
                SubscriptionVideo::firstOrCreate([
                    'subscription_id' => $subscription->id,
                    'video_id'        => $video->id
                ]);

                // 派发下载详情任务
                PullVideoInfoJob::dispatchWithRateLimit($item['bvid']);
            }

            $loaded += count($videos);

            // 逻辑控制：如果是增量更新 (!pullAll)，只抓第一页就结束
            if (! $pullAll) {
                break;
            }

            // 逻辑控制：如果取回的数量少于每页大小（通常20），说明是最后一页了
            // 这里硬编码 20 或者读取配置 config('services.bilibili.fav_videos_page_size')
            if (count($videos) < 20) {
                break;
            }

            // 继续下一页
            $page++;
            
            // 简单休眠防止风控
            usleep(1000000); 
        }

        // 更新订阅状态
        // 注意：收藏夹API一般不直接返回总数 total，或者需要单独调 info 接口
        // 这里暂时用 $loaded 更新 total 可能会导致总数变少（如果是增量更新）
        // 建议仅在 pullAll 为 true 时更新 total，或者保留原值
        if ($pullAll) {
            $subscription->total = $loaded;
        }
        
        $subscription->last_check_at = now();
        $subscription->save();

        // 触发订阅更新事件
        event(new SubscriptionUpdated($oldSubscription, $subscription->toArray()));

        return $subscription;
    }

}
