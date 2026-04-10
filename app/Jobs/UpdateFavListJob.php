<?php
namespace App\Jobs;

use App\Services\VideoManager\Actions\Favorite\UpdateFavoritesAction;
use Log;

class UpdateFavListJob extends BaseScheduledRateLimitedJob
{
    public function __construct()
    {
    }

    protected function getRateLimitKey(): string
    {
        return 'update_job';
    }

    /**
     * 具体的处理逻辑
     */
    protected function process(): void
    {
        Log::info('更新收藏夹列表开始');

        app(UpdateFavoritesAction::class)->execute();

        Log::info('更新收藏夹列表完成');
    }
}
