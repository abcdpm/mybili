<?php
namespace App\Jobs;

use App\Services\VideoManager\Actions\Favorite\UpdateFavoritesAction;
use Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateFavListJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $tries = 3;
    public $timeout = 600;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * 具体的处理逻辑
     */
    public function handle(): void
    {
        Log::info('更新收藏夹列表开始');

        app(UpdateFavoritesAction::class)->execute();

        Log::info('更新收藏夹列表完成');
    }
}
