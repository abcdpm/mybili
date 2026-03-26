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
    public $delay = 5;

    // 保证 10 秒内，Redis 队列里绝对不会出现第二个名为 `rebuild_videos_cache` 的任务。
    public $uniqueFor = 10;

    /**
     * Create the event listener.
     */
    public function __construct(
        protected VideoServiceInterface $videoService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(VideoUpdated|VideoPartUpdated|VideoPartDownloaded $event): void
    {
        // Laravel 底层会自动在 dispatch 时拦截重复事件，
        // 连队列都不会进，彻底告别刷屏和队列风暴。
        $this->videoService->updateVideosCache();
    }
}
