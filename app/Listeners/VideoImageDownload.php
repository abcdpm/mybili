<?php
namespace App\Listeners;

use App\Events\VideoUpdated;
use App\Models\Video;
use App\Services\CoverService;
use Log;

class VideoImageDownload
{
    /**
     * Create the event listener.
     */
    public function __construct(public CoverService $coverService)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(VideoUpdated $event): void
    {
        $oldVideo = $event->oldVideo;
        $newVideo = $event->newVideo;

        $oldCover = $oldVideo['cover'] ?? '';
        $newCover = $newVideo['cover'] ?? '';

        if ($newVideo['invalid']) {
            Log::info('[视频封面] 视频无效, 跳过封面下载', ['id' => $newVideo['id'], 'bvid' => $newVideo['bvid'], 'title' => $newVideo['title']]);
            return;
        }

        $resourceId = $newVideo['id'] ?? '';
        if (! $resourceId) {
            Log::info('[视频封面] 视频ID为空, 跳过封面下载', ['newVideo' => $newVideo]);
            return;
        }
        $resource = Video::find($resourceId);
        if ($oldCover != $newCover && $newCover != '' && $resource != null) {
            Log::info('[视频封面] 开始下载封面任务', ['cover' => $newCover, 'resourceId' => $resourceId]);
            if ($this->coverService->isCoverable($newCover, $resource)) {
                Log::info('[视频封面] 封面已存在, 跳过封面下载', ['cover' => $newCover, 'resourceId' => $resourceId]);
                return;
            }

            $this->coverService->downloadCoverImageJob($newCover, 'video', $resource);
            Log::info('[视频封面] 封面下载完成', ['cover' => $newCover, 'resourceId' => $resourceId]);
        }
    }
}
