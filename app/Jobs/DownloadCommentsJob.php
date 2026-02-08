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

    // [修改] 增加 limit 属性
    public function __construct(
        public Video $video,
        public ?int $limit = null 
    ) {}

    public function handle(DownloadCommentsAction $action): void
    {
        // [修改] 将 limit 传给 Action
        $action->execute($this->video, $this->limit);
    }
}