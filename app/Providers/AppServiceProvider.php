<?php

namespace App\Providers;

use App\Models\Video;
use App\Models\VideoPart;
use App\Models\Danmaku;
use App\Models\Comment;
use App\Models\Emote;
use App\Models\FavoriteList;
use App\Contracts\DownloadImageServiceInterface;
use App\Contracts\TelegramBotServiceInterface;
use App\Services\ColorExtractionService;
use App\Services\DownloadImageService;
use App\Services\TelegramBotService;
use App\Services\VideoManager\Contracts\DanmakuServiceInterface;
use App\Services\VideoManager\Contracts\FavoriteServiceInterface;
use App\Services\VideoManager\Contracts\VideoServiceInterface;
use App\Services\VideoManager\DanmakuService;
use App\Services\VideoManager\FavoriteService;
use App\Services\VideoManager\VideoService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use App\Database\PragmaConnector;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SQLite 连接统一应用 PRAGMA（WAL / busy_timeout / 同步级别等）。
        // 通过自定义连接器，确保 Web、Octane、队列 Worker、Artisan 命令
        // 的每一条 SQLite 连接都获得一致的并发优化（历史版本仅对启动时连接生效）。
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            $connector = new PragmaConnector();

            return new SQLiteConnection($connector->connect($config), $database, $prefix, $config);
        });

        $this->app->singleton(DownloadImageServiceInterface::class, DownloadImageService::class);
        $this->app->singleton(TelegramBotServiceInterface::class, TelegramBotService::class);

        $this->app->singleton(DanmakuServiceInterface::class, DanmakuService::class);
        $this->app->singleton(FavoriteServiceInterface::class, FavoriteService::class);
        $this->app->singleton(VideoServiceInterface::class, VideoService::class);

        // 颜色提取服务 - 使用配置文件中的参数
        $this->app->singleton(ColorExtractionService::class, function ($app) {
            return new ColorExtractionService(
                method: config('color.extraction_method', 'octree'),
                sampleSize: config('color.sample_size', 100),
                kmeansK: config('color.kmeans_k', 5)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 注：SQLite 的 WAL / busy_timeout 等 PRAGMA 优化已统一收敛到
        // App\Database\PragmaConnector（见 register()），对所有连接生效。

        // 自动监听数据的新增与删除，实现毫秒级数量增量更新
        Video::created(fn() => Cache::increment('stat_videos'));
        Video::deleted(fn() => Cache::decrement('stat_videos'));

        VideoPart::created(fn() => Cache::increment('stat_video_parts'));
        VideoPart::deleted(fn() => Cache::decrement('stat_video_parts'));

        Danmaku::created(fn() => Cache::increment('stat_danmaku'));
        Danmaku::deleted(fn() => Cache::decrement('stat_danmaku'));

        Comment::created(fn() => Cache::increment('stat_comments'));
        Comment::deleted(fn() => Cache::decrement('stat_comments'));

        Emote::created(fn() => Cache::increment('stat_emotes'));
        Emote::deleted(fn() => Cache::decrement('stat_emotes'));

        FavoriteList::created(fn() => Cache::increment('stat_favorite_lists'));
        FavoriteList::deleted(fn() => Cache::decrement('stat_favorite_lists'));
    }
}
