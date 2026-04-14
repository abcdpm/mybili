<?php
namespace App\Jobs;

use App\Models\VideoPart;
use App\Services\DownloadQueueService;
use App\Services\VideoManager\Actions\Video\DownloadVideoPartFileAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DownloadVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 视频下载耗时可能较长，可以适当增加超时时间（单位：秒）
    public $timeout = 3600;
    public $tries = 1;

    public function __construct(public VideoPart $videoPart)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try{
            // 如果视频被删除，则不下载
            if($this->videoPart->video->trashed()){
                Log::info('video deleted, skip download', ['video_id' => $this->videoPart->video_id, 'part' => $this->videoPart->part]);
                app(DownloadQueueService::class)->markFailedByVideoPart($this->videoPart->id, 'video deleted');
                return;
            }

            app(DownloadVideoPartFileAction::class)->execute($this->videoPart);
        } catch (\App\Exceptions\ApiGetVideoStatusException $e) {
            // 稿件状态异常，跳过下载
            Log::error('video manuscript status abnormal', ['video_id' => $this->videoPart->video_id, 'part' => $this->videoPart->part, 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            // 标记视频失败不下载
            app(DownloadQueueService::class)->markFailedByVideoPart($this->videoPart->id, sprintf('video manuscript status abnormal: %s', $e->getMessage()));
            return;
        }
        app(DownloadQueueService::class)->markDoneByVideoPart($this->videoPart->id);

        // 下载完成后立刻触发一次队列消费，避免等下一分钟的计划任务（拆分为独立 Job）
        TriggerProcessDownloadQueueJob::dispatch()
            ->delay(now()->addSeconds(1))
            ->onQueue('fast');
    }

    public function failed(\Throwable $exception): void
    {
        app(DownloadQueueService::class)->markRetryOrFailedByVideoPart(
            $this->videoPart->id,
            $exception->getMessage()
        );
    }

    public function displayName(): string
    {
        if($this->videoPart->video && $this->videoPart->video->trashed()){
            return sprintf('DownloadVideoJob %s-%s (deleted)', $this->videoPart->video_id, $this->videoPart->page);
        }
        if ($this->videoPart->video->title && $this->videoPart->video->title != $this->videoPart->part) {
            return sprintf('DownloadVideoJob %s-%s %s-%s', $this->videoPart->video_id, $this->videoPart->page, $this->videoPart->video->title, $this->videoPart->part);
        } else {
            return sprintf('DownloadVideoJob %s-%s %s', $this->videoPart->video_id, $this->videoPart->page, $this->videoPart->part);
        }
    }
}