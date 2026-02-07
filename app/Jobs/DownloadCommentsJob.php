<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoManager\Actions\Video\DownloadCommentsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadCommentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Video $video)
    {}

    public function handle(DownloadCommentsAction $action): void
    {
        $action->execute($this->video);
    }
}