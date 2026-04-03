<?php
namespace App\Services\VideoManager\Actions\Video;

use App\Enums\SettingKey;
use App\Models\Video;
use App\Models\VideoPart;
use App\Services\DownloadFilterService;
use App\Services\DownloadQueueService;
use App\Services\DownloadVideoService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Log;
use Storage;
use Str;

class CheckVideoPartFileToDownloadAction
{
    public function __construct(
        public SettingsService $settingsService,
        public DownloadVideoService $downloadVideoService,
        public DownloadFilterService $downloadFilterService,
        public DownloadQueueService $downloadQueueService
    ) {
    }

    public function execute(VideoPart $videoPart, bool $tryDownload = false): void
    {
        $video = Video::where('id', $videoPart->video_id)->first();

        if (! $video) {
            Log::info('[视频下载] 视频未找到或已删除', ['id' => $videoPart->video_id]);
            return;
        }

        if ($this->downloadFilterService->shouldExcludeByFavTime($video)) {
            Log::info('Download excluded by favorite time', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
            return;
        }

        // 检查是否能下载该收藏夹或订阅的视频：只要有一个收藏夹或订阅未被过滤就继续下载
        if ($this->downloadFilterService->shouldExcludeByVideo($video)) {
            Log::info('Download excluded by favorite and subscription', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
            return;
        }

        // 获取当前分片在视频第几个索引
        $videoParts = VideoPart::where('video_id', $video->id)->select(['video_id', 'page', 'cid'])->get();

        $isNumberNamed = false;
        foreach ($videoParts as $item) {
            if (preg_match('/^\d+$/', $item['page']) && intval($item['page']) == $item['page']) {
                $isNumberNamed = true;
            } else {
                $isNumberNamed = false;
                break;
            }
        }

        $currentIndex = 1;
        if ($isNumberNamed) {
            $currentIndex = $videoPart->page;
        } else {
            $videoParts   = $videoParts->sortBy('video_id');
            $currentIndex = $videoParts->search(function ($item) use ($videoPart) {
                return $item['cid'] == $videoPart->cid;
            }) + 1;
        }

        $isDownloaded = false;
        if ($videoPart->video_download_path) {
            $savePath = Storage::disk('public')->path(Str::after($videoPart->video_download_path, '/storage/'));
            if (is_file($savePath)) {
                $isDownloaded = true;
            }
        } else {
            $savePath = $this->downloadVideoService->getOldDownloadPath($video->id, $currentIndex);
            if (is_file($savePath)) {
                $isDownloaded = true;
            }
        }

        if (! $isDownloaded) {
            if (! $tryDownload) {
                return;
            }

            if ($this->settingsService->get(SettingKey::VIDEO_DOWNLOAD_ENABLED) == 'on') {

                if ($this->downloadFilterService->shouldExcludeByDuration(intval($video->duration))) {
                    Log::info('[视频下载] 视频文件不存在, 因总时长触发排除规则', ['id' => $video->id, 'title' => $video->title]);
                    return;
                }

                if ($this->downloadFilterService->shouldExcludeByDurationPart($videoPart->duration)) {
                    Log::info('[视频下载] 视频分片文件不存在, 因分片时长触发排除规则', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
                    return;
                }

                $this->downloadQueueService->enqueueVideo($videoPart);
            } else {
                Log::info('[视频下载] 视频分片文件不存在, 设置-视频下载功能未开启', ['id' => $videoPart->cid, 'title' => $videoPart->part]);
            }
        } else {
            Log::info('[视频下载] 视频分片文件已存在, 更新数据库记录', ['id' => $videoPart->cid, 'title' => $videoPart->part]);

            $videoPart->video_downloaded_at = Carbon::createFromTimestamp(filectime($savePath));
            $videoPart->video_download_path = get_relative_path($savePath);
            $videoPart->save();
        }

        $video->video_downloaded_num = VideoPart::where('video_id', $video->id)->whereNotNull('video_download_path')->count();
        $video->save();
    }
}
