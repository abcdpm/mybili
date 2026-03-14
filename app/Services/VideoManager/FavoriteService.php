<?php
namespace App\Services\VideoManager;

use App\Models\FavoriteList;
use App\Models\Subscription;
use App\Services\VideoManager\Contracts\FavoriteServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class FavoriteService implements FavoriteServiceInterface
{
    /**
     * 获取收藏夹或订阅的统一列表（兼容收藏夹界面）
     * @return array
     */
    public function getUnifiedContentList(): array
    {
        $favList = FavoriteList::query()
            ->orderBy('sort_order', 'asc') 
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();
            
        $subscriptions = Subscription::query()
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();

        $unifiedList = [];

        // 添加收藏夹（正数ID）
        foreach ($favList as $fav) {
            $unifiedList[] = [
                'id'              => $fav['id'],
                'type'            => 'favorite',
                'title'           => $fav['title'] ?? '',
                'description'     => $fav['intro'] ?? '',
                'cover'           => $fav['cover'] ?? '',
                'media_count'     => $fav['media_count'] ?? 0,
                'created_at'      => $fav['created_at'] ? strtotime($fav['created_at']) : null,
                'updated_at'      => $fav['updated_at'] ? strtotime($fav['updated_at']) : null,
                'ctime'           => $fav['ctime'] ? $fav['ctime'] : null,
                'mtime'           => $fav['mtime'] ? $fav['mtime'] : null,
                'cover_info'      => null,
                'sort_order'      => $fav['sort_order'] ?? 0, // 【新增】提取排序字段
            ];
        }

        // 添加订阅（负数ID）
        foreach ($subscriptions as $subscription) {
            $unifiedList[] = [
                'id'              => -$subscription['id'], // 转换为负数ID
                'type'            => 'subscription',
                'title'           => $subscription['name'] ?? '',
                'description'     => $subscription['description'] ?? '',
                'cover'           => $subscription['cover'] ?? '',
                'media_count'     => intval($subscription['total'] ?? 0),
                'created_at'      => $subscription['created_at'] ? strtotime($subscription['created_at']) : null,
                'updated_at'      => $subscription['updated_at'] ? strtotime($subscription['updated_at']) : null,
                'ctime'           => $subscription['created_at'] ? strtotime($subscription['created_at']) : null,
                'mtime'           => $subscription['updated_at'] ? strtotime($subscription['updated_at']) : null,
                'cover_info'      => null,
                'sort_order'      => $subscription['sort_order'] ?? 0, // 【新增】提取排序字段
            ];
        }

        // 【核心新增】对合并后的混合数组进行全局排序
        usort($unifiedList, function ($a, $b) {
            // 优先按照 sort_order 升序排
            if ($a['sort_order'] !== $b['sort_order']) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            // 如果 sort_order 相同（比如都是新加的，默认为0），则按内部真实ID降序（新添加的在前）
            return abs($b['id']) <=> abs($a['id']);
        });

        return $unifiedList;
    }

    /**
     * 获取收藏夹或订阅的统一详情（兼容收藏夹界面）
     * @param int $id 正数表示收藏夹ID，负数表示订阅ID
     * @param array $columns
     * @return object|null 返回统一格式的内容对象
     */
    public function getUnifiedContentDetail(int $id, array $columns = ['*']): ?object
    {
        if ($this->isSubscription($id)) {
            $subscription = Subscription::query()->where('id', abs($id))->first($columns);

            if (! $subscription) {
                return null;
            }

            // 转换为统一格式
            $unifiedContent = (object) [
                'id'                => -$subscription->id, // 保持负数ID
                'type'              => 'subscription',
                'title'             => $subscription->name ?? '',
                'description'       => $subscription->description ?? '',
                'cover'             => $subscription->cover ?? '',
                'media_count'       => intval($subscription->total ?? 0),
                'created_at'        => $subscription->created_at ? strtotime($subscription->created_at) : null,
                'updated_at'        => $subscription->updated_at ? strtotime($subscription->updated_at) : null,
                'subscription_type' => $subscription->type ?? '',
                'mid'               => $subscription->mid ?? null,
                'season_id'         => $subscription->season_id ?? null,
                'status'            => $subscription->status ?? null,
                'last_check_at'     => $subscription->last_check_at ? strtotime($subscription->last_check_at) : null,
            ];

            // 加载关联的视频
            $unifiedContent->videos = $subscription->videos()->orderBy('pubtime', 'desc')->orderBy('created_at', 'desc')->get();

            return $unifiedContent;
        } else {
            // 收藏夹
            $fav = FavoriteList::query()->where('id', $id)->first($columns);

            if (! $fav) {
                return null;
            }

            // 转换为统一格式
            $unifiedContent = (object) [
                'id'          => $fav->id,
                'type'        => 'favorite',
                'title'       => $fav->title ?? '',
                'description' => $fav->intro ?? '',
                'cover'       => $fav->cover ?? '',
                'media_count' => $fav->media_count ?? 0,
                'created_at'  => $fav->created_at ? strtotime($fav->created_at) : null,
                'updated_at'  => $fav->updated_at ? strtotime($fav->updated_at) : null,
                'fid'         => $fav->fid ?? null,
                'mid'         => $fav->mid ?? null,
            ];

            // 加载关联的视频
            $unifiedContent->videos = $fav->videos()->orderBy('fav_time', 'desc')->orderBy('created_at', 'desc')->get();

            return $unifiedContent;
        }
    }

    /**
     * 判断ID是否为订阅
     * @param int $id
     * @return bool
     */
    public function isSubscription(int $id): bool
    {
        return $id < 0;
    }

    /**
     * 判断ID是否为收藏夹
     * @param int $id
     * @return bool
     */
    public function isFavorite(int $id): bool
    {
        return $id > 0;
    }

    public function getFavorites(): Collection  
    {
        // 【修改】：如果有其他地方调用这个方法，也加上同样的排序规则
        return FavoriteList::query()
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'desc')
            ->get();
    }
}
