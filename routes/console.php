<?php

use App\Services\VideoManager\Contracts\VideoServiceInterface;
use App\Enums\SettingKey;
use App\Services\SettingsService;
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
        Artisan::call('app:sync-media', ['--fav-list' => true]);
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
        if (app(VideoServiceInterface::class)->count() == 0) {
            Artisan::call('app:sync-media', ['--fav-videos' => true]);
            return;
        }
        if (now()->format('H') === '04') {
            return;
        }
        Artisan::call('app:sync-media', ['--fav-videos' => true, '--fav-page' => 1]);
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
        Artisan::call('app:sync-media', ['--fav-videos' => true]);
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
    Artisan::call('app:sync-media', ['--fix-invalid' => true]);
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
    Artisan::call('app:sync-media', ['--subscriptions' => true]);
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

// ==========================================================================
// 11. 增量更新视频评论
// ==========================================================================
// 作用：启动轮询，每天动态抽取最久未更新评论的视频进行状态刷新。
//      默认按照有效视频总数的 3% 抽取任务，自适应抓取热门/最新评论。
// 频率：每天凌晨 3:00执行一次
// Schedule::command('app:download-all-comments --incremental=20 --sleep=5 --max-videos=3000')->dailyAt('3:00'); // 旧增量更新方案
Schedule::command('app:download-all-comments --auto-update --percent=3 --incremental=20 --sleep=5')->dailyAt('3:00');

// ==========================================================================
// 12. 处理下载队列
// ==========================================================================
// 每分钟从下载队列取出任务并派发 Job
Schedule::command('app:process-download-queue')
    ->everyMinute()
    ->withoutOverlapping();

// ==========================================================================
// 13. 系统空间与数据库使用情况全量统计
// ==========================================================================
// 作用：全量统计数据库各表行数及文件夹物理大小，解决实时获取极度缓慢的问题。
// 频率：每周一早晨 06:00 执行一次
Schedule::command('app:calculate-system-stats')
    ->name('calculate-system-stats')
    ->withoutOverlapping()
    ->weeklyOn(1, '6:00');

// ==========================================================================
// 14. 自动清理并限制系统日志大小
// ==========================================================================
// 作用：防止 laravel.log 文件无限增长撑爆磁盘。如果文件超过 50MB，则只保留最新的 5000 行。
// 频率：每天凌晨 3:30 执行一次
Schedule::call(function () {
    $logPath = storage_path('logs/laravel.log');
    
    // 如果日志文件存在，且大小超过 50MB (50 * 1024 * 1024 bytes)
    if (file_exists($logPath) && filesize($logPath) > 52428800) {
        $tmpPath = $logPath . '.tmp';
        // 利用 Linux 的 tail 命令极速提取最后 5000 行并覆盖原文件
        $cmd = sprintf(
            'tail -n 5000 %s > %s && mv %s %s', 
            escapeshellarg($logPath), 
            escapeshellarg($tmpPath), 
            escapeshellarg($tmpPath), 
            escapeshellarg($logPath)
        );
        exec($cmd);
        
        Log::info('系统日志文件已成功瘦身，保留最新的 5000 行。');
    }
})
->name('rotate-system-logs')
->dailyAt('3:30');

// ==========================================================================
// 15. 每日平滑更新视频播放量 (防风控)
// ==========================================================================
// 作用：每天抽取最久未更新的 3% 的视频放入队列，并且任务之间强制间隔 5 秒执行。
// 优点：既能保持播放量数据鲜活，又能彻底避免 10 个队列进程瞬间并发打满被 B 站封禁。
// 频率：每天凌晨 05:00 执行一次
Schedule::command('app:update-video-stats --auto-update --percent=3 --sleep=5')
    ->name('update-video-stats-daily')
    ->withoutOverlapping()
    ->dailyAt('05:00');