<?php
namespace App\Listeners;

use App\Events\VideoPartDownloaded;
use App\Events\VideoPartUpdated;
use App\Events\VideoUpdated;
use App\Services\VideoManager\Contracts\VideoServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\Silenced;

class UpdateVideosCache implements ShouldQueue, ShouldBeUnique, Silenced
{
    public $queue = 'fast';

    // 收到事件后，任务会等待 5 秒再执行。在这 5 秒的缓冲期内，
    // 刚好可以等待大批量的数据库写操作完成。
    // 延迟 5 秒执行，合并这 5 秒内的所有更新
    public $delay = 5;

    // 锁的兜底超时时间（可以设长一点，因为一旦开始执行锁就会被主动释放）
    public $uniqueFor = 60;

    /**
     * Create the event listener.
     */
    public function __construct(
        protected VideoServiceInterface $videoService
    ) {}

    // 指定唯一 ID
    public function uniqueId(): string
    {
        return 'rebuild_videos_cache';
    }

    /**
     * Handle the event.
     */
    public function handle(VideoUpdated|VideoPartDownloaded $event): void
    {
        // Laravel 底层会自动在 dispatch 时拦截重复事件，
        // 连队列都不会进，彻底告别刷屏和队列风暴。
        // 直接全量更新缓存，底层会自动防抖且保证不漏尾刀
        $this->videoService->updateVideosCache();
    }
}
