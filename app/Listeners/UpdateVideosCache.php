<?php

namespace App\Listeners;

use App\Events\VideoUpdated;
use App\Services\VideoManager\Contracts\VideoServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class UpdateVideosCache implements ShouldQueue
{
    public $queue = 'fast';

    /**
     * Create the event listener.
     */
    public function __construct(
        protected VideoServiceInterface $videoService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(VideoUpdated $event): void
    {
        // [新增] 防抖逻辑
        // 尝试获取一个持续 20 秒的锁
        // 如果获取失败（说明最近 20 秒内已经有一个任务在跑了），则直接跳过本次任务
        // block(0) 表示不等待，立即返回 false
        $lock = Cache::lock('locking:update_all_videos_cache', 20);
        
        if ($lock->get()) {
            try {
                // 只有拿到锁的任务才执行全量更新
                app(VideoServiceInterface::class)->updateVideosCache();
            } finally {
                // [可选] 这里不释放锁，让它自动过期
                // 这样可以强制保证 20 秒内只执行一次，给数据库喘息的机会
                // $lock->release(); 
            }
        } else {
            // Log::debug('Update videos cache skipped (throttled)');
        }
    }
}
