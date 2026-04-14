<?php

namespace App\Jobs;

use App\Services\VideoManager\Actions\Video\FixFavoriteInvalidVideoAction;
use Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FixInvalidFavVideosJob extends ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $tries = 3;
    public $timeout = 600;

    public function __construct(public array $fav, public int $page)
    {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[修复失效视频] 修复失效视频任务开始');
        app(FixFavoriteInvalidVideoAction::class)->execute($this->fav['id'], $this->page);
        Log::info('[修复失效视频] 修复失效视频任务结束', ['fav_title' => $this->fav['title'], 'page' => $this->page]);
    }

    public function displayName(): string
    {
        return __CLASS__ . ' ' . $this->fav['title'] . ' page: ' . $this->page;
    }
}
