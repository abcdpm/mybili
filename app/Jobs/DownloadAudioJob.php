<?php
namespace App\Jobs;

use App\Models\AudioPart;
use App\Services\DownloadQueueService;
use App\Services\VideoManager\Actions\Audio\DownloadAudioPartFileAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(public AudioPart $audioPart)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        app(DownloadAudioPartFileAction::class)->execute($this->audioPart);
        app(DownloadQueueService::class)->markDoneByAudio($this->audioPart->video_id);
    }

    public function failed(\Throwable $exception): void
    {
        app(DownloadQueueService::class)->markRetryOrFailedByAudio(
            $this->audioPart->video_id,
            $exception->getMessage()
        );
    }

    public function displayName(): string
    {
        return sprintf('DownloadAudioJob au%s', $this->audioPart->sid);
    }
}