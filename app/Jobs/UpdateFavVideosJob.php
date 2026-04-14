<?php
namespace App\Jobs;

use App\Services\VideoManager\Actions\Favorite\UpdateFavoriteVideosAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateFavVideosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600;
    public $backoff = [60, 120]; // 失败重试的延迟阶梯

    public function __construct(public array $fav, public ?int $page = null)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('[收藏夹管理] 收藏夹视频更新任务开始');
        app(UpdateFavoriteVideosAction::class)->execute($this->fav, $this->page);
        Log::info('[收藏夹管理] 收藏夹视频更新任务完成', ['fav_title' => $this->fav['title'], 'page' => $this->page]);
    }

    public function displayName(): string
    {
        return __CLASS__ . ' ' . $this->fav['title'] . ' page: ' . $this->page;
    }
}