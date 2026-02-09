<?php

use App\Services\VideoManager\Contracts\VideoServiceInterface;
use App\Enums\SettingKey;
use App\Services\SettingsService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// 同步收藏夹信息 更新收藏夹本身的元数据（如标题、媒体数量等）
// 每 10 分钟执行一次
Schedule::call(function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == "on") {
        Artisan::call('app:update-fav', ['--update-fav' => true]);
    }
})
->name('update-fav')
->withoutOverlapping()
->everyTenMinutes();

// 增量更新收藏夹视频 (只更新第一页)
// 每 10 分钟执行一次
Schedule::call(function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == "on") {
        // 如果是首次运行则全量更新
        if(app(VideoServiceInterface::class)->count() == 0){
            Artisan::call('app:update-fav', ['--update-fav-videos' => true]);
            return;
        }
        // 如果当前时间在凌晨4点范围内,跳过执行
        if (now()->format('H') === '04') {
            return;
        }
        Artisan::call('app:update-fav', ['--update-fav-videos' => true, '--update-fav-videos-page' => 1]);
    }
})
->name('update-fav-videos-page-1')
->withoutOverlapping()
->everyTenMinutes();

// 全量更新收藏夹视频
// 每天凌晨 4:00 执行一次
Schedule::call(function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == "on") {
        Artisan::call('app:update-fav', ['--update-fav-videos' => true]);
    }
})
->name('update-fav-videos-all')
->withoutOverlapping()
->dailyAt('04:00');

Schedule::call(function () {
    Artisan::call('app:update-fav', ['--fix-invalid-fav-videos' => true]);
})
->name('fix-invalid-fav-videos')
->withoutOverlapping()
->dailyAt('04:00');


Schedule::command('stats:send')->daily();

// Horizon metrics snapshot - 每分钟收集队列指标数据
Schedule::command('horizon:snapshot')->everyMinute();


// 生成可读文件名
// 每天凌晨 6:00 执行一次
Schedule::call(function () {
    $humanReadableNameEnable = app(SettingsService::class)->get(SettingKey::HUMAN_READABLE_NAME_ENABLED);
    if ($humanReadableNameEnable == "on") {
        Artisan::call('app:make-human-readable-names');
    }
})
->name('make-human-readable-names')
->withoutOverlapping()
// ->everyTenMinutes();
->dailyAt('6:00')
->runInBackground();


Schedule::call(function () {
    app(SubscriptionService::class)->updateSubscriptions();
})
->name('update-subscription')
->withoutOverlapping()
->everyTenMinutes();


Schedule::command('app:update-no-parts-valid-video')->hourly();

// 更新收藏夹封面图
// 每小时执行一次
Schedule::command('app:scan-cover-image', ['--target=favorite'])->hourly();