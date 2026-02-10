<?php

use App\Services\VideoManager\Contracts\VideoServiceInterface;
use App\Enums\SettingKey;
use App\Services\SettingsService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ==========================================================================
// 1. 收藏夹元数据同步
// ==========================================================================
// 作用：仅更新收藏夹本身的列表信息（如收藏夹标题、包含媒体数量等），不涉及具体视频内容的更新。
// 频率：每 10 分钟执行一次
Schedule::call(function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == "on") {
        Artisan::call('app:update-fav', ['--update-fav' => true]);
    }
})
->name('update-fav')
->withoutOverlapping()
->everyTenMinutes();

// ==========================================================================
// 2. 收藏夹视频增量更新 (快速扫描)
// ==========================================================================
// 作用：快速检测收藏夹是否有新视频加入。
// 逻辑：
//   - 如果是首次运行（本地无视频），强制进行全量更新。
//   - 凌晨 04:00 小时段跳过执行（避免与下方的全量更新任务冲突）。
//   - 正常情况下只获取收藏夹的“第一页”数据，以节省 API 资源并降低风控风险。
// 频率：每 10 分钟执行一次
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

// ==========================================================================
// 3. 收藏夹视频全量同步 (深度扫描)
// ==========================================================================
// 作用：扫描收藏夹的所有分页。
// 目的：确保本地数据与 B 站完全一致，发现被删除的视频或漏掉的视频。
// 频率：每天凌晨 04:00 执行一次
Schedule::call(function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == "on") {
        Artisan::call('app:update-fav', ['--update-fav-videos' => true]);
    }
})
->name('update-fav-videos-all')
->withoutOverlapping()
->dailyAt('04:00');

// ==========================================================================
// 4. 修复失效视频状态
// ==========================================================================
// 作用：检查本地标记为“失效”的收藏夹视频，确认它们是否恢复正常，或者进行清理操作。
// 频率：每天凌晨 04:00 执行一次
Schedule::call(function () {
    Artisan::call('app:update-fav', ['--fix-invalid-fav-videos' => true]);
})
->name('fix-invalid-fav-videos')
->withoutOverlapping()
->dailyAt('04:00');

// ==========================================================================
// 5. 匿名统计上报
// ==========================================================================
// 作用：发送非敏感的程序使用统计数据（用于项目改进分析）。
// 频率：每天执行一次
Schedule::command('stats:send')->daily();

// ==========================================================================
// 6. Horizon 队列监控快照
// ==========================================================================
// 作用：记录 Laravel Horizon 的队列负载、吞吐量等指标，用于生成仪表盘图表。
// 频率：每分钟执行一次
Schedule::command('horizon:snapshot')->everyMinute();

// ==========================================================================
// 7. 生成人类可读文件名 (硬链接整理)
// ==========================================================================
// 作用：将下载的视频文件通过硬链接或符号链接整理成 Emby/Plex 友好的命名格式。
// 频率：每天早晨 06:00 执行一次
Schedule::call(function () {
    $humanReadableNameEnable = app(SettingsService::class)->get(SettingKey::HUMAN_READABLE_NAME_ENABLED);
    if ($humanReadableNameEnable == "on") {
        Artisan::call('app:make-human-readable-names');
    }
})
->name('make-human-readable-names')
->withoutOverlapping()
// ->everyTenMinutes();
->dailyAt('6:00');

// ==========================================================================
// 8. 订阅更新 (UP主/番剧)
// ==========================================================================
// 作用：检查已订阅的 UP 主或系列是否有新发布的视频。
// 频率：每 10 分钟执行一次
Schedule::call(function () {
    app(SubscriptionService::class)->updateSubscriptions();
})
->name('update-subscription')
->withoutOverlapping()
->everyTenMinutes();

// ==========================================================================
// 9. 修复无分P信息的有效视频
// ==========================================================================
// 作用：扫描数据库中标记为有效但缺少分P（Pages/Parts）信息的视频，并尝试重新获取元数据。
// 频率：每小时执行一次
Schedule::command('app:update-no-parts-valid-video')->hourly();

// ==========================================================================
// 10. 扫描/下载收藏夹封面
// ==========================================================================
// 作用：专门下载收藏夹及其视频缺失的封面图片。
// 频率：每小时执行一次
Schedule::command('app:scan-cover-image', ['--target=favorite'])->hourly();