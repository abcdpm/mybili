<?php

use App\Enums\SettingKey;
use App\Services\ScheduleConfigService;
use App\Services\SettingsService;
use App\Services\VideoManager\Contracts\VideoServiceInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// ==========================================================================
// 定时任务配置化（第 14 项）
// ==========================================================================
// 所有任务的 启用/频率/执行时刻 由 ScheduleConfigService 从 settings 读取：
//  - $taskGate($key, $body)：运行时闸门，任务被禁用时零开销跳过；
//  - ScheduleConfigService::apply()：按配置生成任务名与 cron 表达式。
// 默认配置与旧版硬编码调度完全一致（无配置时行为不变），配置修改约 1 分钟内生效。
$taskGate = function (string $key, \Closure $body): \Closure {
    return function () use ($key, $body) {
        if (! app(ScheduleConfigService::class)->isEnabled($key)) {
            return;
        }
        $body();
    };
};
$task = fn (string $key) => app(ScheduleConfigService::class);

// ==========================================================================
// 1. 收藏夹元数据同步
// ==========================================================================
// 作用：仅更新收藏夹本身的列表信息（如收藏夹标题、包含媒体数量等），不涉及具体视频内容的更新。
$e = Schedule::call($taskGate('update_fav', function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == 'on') {
        Artisan::call('app:sync-media', ['--fav-list' => true]);
    }
}));
$task('update_fav')->apply($e, 'update_fav');
$e->withoutOverlapping();

// ==========================================================================
// 2. 收藏夹视频增量更新 (快速扫描)
// ==========================================================================
// 作用：快速检测收藏夹是否有新视频加入；仅获取第一页数据以节省 API 资源并降低风控风险。
//      首次运行（本地无视频）强制全量更新；凌晨 4 点时段让位全量扫描。
$e = Schedule::call($taskGate('update_fav_videos', function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == 'on') {
        if (app(VideoServiceInterface::class)->count() == 0) {
            Artisan::call('app:sync-media', ['--fav-videos' => true]);

            return;
        }
        if (now()->format('H') === '04') {
            return;
        }
        Artisan::call('app:sync-media', ['--fav-videos' => true, '--fav-page' => 1]);
    }
}));
$task('update_fav_videos')->apply($e, 'update_fav_videos');
$e->withoutOverlapping();

// ==========================================================================
// 3. 收藏夹视频全量同步 (深度扫描)
// ==========================================================================
// 作用：扫描收藏夹的所有分页，确保本地数据与 B 站完全一致，发现被删除或漏掉的视频。
$e = Schedule::call($taskGate('update_fav_videos_all', function () {
    $updateFavEnable = app(SettingsService::class)->get(SettingKey::FAVORITE_SYNC_ENABLED);
    if ($updateFavEnable == 'on') {
        Artisan::call('app:sync-media', ['--fav-videos' => true]);
    }
}));
$task('update_fav_videos_all')->apply($e, 'update_fav_videos_all');
$e->withoutOverlapping();

// ==========================================================================
// 4. 修复失效视频状态
// ==========================================================================
// 作用：检查本地标记为"失效"的收藏夹视频，确认它们是否恢复正常，或者进行清理操作。
$e = Schedule::call($taskGate('fix_invalid_fav_videos', function () {
    Artisan::call('app:sync-media', ['--fix-invalid' => true]);
}));
$task('fix_invalid_fav_videos')->apply($e, 'fix_invalid_fav_videos');
$e->withoutOverlapping();

// ==========================================================================
// 5. 匿名统计上报
// ==========================================================================
// 作用：发送非敏感的程序使用统计数据（用于项目改进分析）。
$e = Schedule::call($taskGate('stats_send', function () {
    Artisan::call('stats:send');
}));
$task('stats_send')->apply($e, 'stats_send');

// ==========================================================================
// 6. Horizon 队列监控快照
// ==========================================================================
// 作用：记录 Laravel Horizon 的队列负载、吞吐量等指标，用于生成仪表盘图表。
$e = Schedule::call($taskGate('horizon_snapshot', function () {
    Artisan::call('horizon:snapshot');
}));
$task('horizon_snapshot')->apply($e, 'horizon_snapshot');

// ==========================================================================
// 7. 生成人类可读文件名 (硬链接整理)
// ==========================================================================
// 作用：将下载的视频文件通过硬链接或符号链接整理成 Emby/Plex 友好的命名格式。
//      受"可读文件名"功能开关（HUMAN_READABLE_NAME_ENABLED）控制。
$e = Schedule::call($taskGate('human_readable_names', function () {
    $humanReadableNameEnable = app(SettingsService::class)->get(SettingKey::HUMAN_READABLE_NAME_ENABLED);
    if ($humanReadableNameEnable == 'on') {
        Artisan::call('app:make-human-readable-names');
    }
}));
$task('human_readable_names')->apply($e, 'human_readable_names');
$e->withoutOverlapping();

// ==========================================================================
// 8. 订阅更新 (UP主/番剧)
// ==========================================================================
// 作用：检查已订阅的 UP 主或系列是否有新发布的视频。
$e = Schedule::call($taskGate('update_subscription', function () {
    Artisan::call('app:sync-media', ['--subscriptions' => true]);
}));
$task('update_subscription')->apply($e, 'update_subscription');
$e->withoutOverlapping();

// ==========================================================================
// 9. 修复无分P信息的有效视频
// ==========================================================================
// 作用：扫描数据库中标记为有效但缺少分P（Pages/Parts）信息的视频，并尝试重新获取元数据。
$e = Schedule::call($taskGate('no_parts_valid', function () {
    Artisan::call('app:update-no-parts-valid-video');
}));
$task('no_parts_valid')->apply($e, 'no_parts_valid');

// ==========================================================================
// 10. 扫描/下载收藏夹封面
// ==========================================================================
// 作用：专门下载收藏夹及其视频缺失的封面图片。
$e = Schedule::call($taskGate('cover_scan', function () {
    Artisan::call('app:scan-cover-image', ['--target=favorite']);
}));
$task('cover_scan')->apply($e, 'cover_scan');

// ==========================================================================
// 11. 增量更新视频评论
// ==========================================================================
// 作用：启动轮询，每天动态抽取最久未更新评论的视频进行状态刷新。
//      任务按 --interval 错峰投递到 comments-batch 队列（默认摊到约 20 小时平稳消化），
//      彻底避免旧版一次性投递 + sleep 阻塞 worker 导致的任务堆积。
// 参数（第 15 项）：由"评论下载设置"（comment_download_config）提供，
//      命令行显式传参时以命令行为准（手动排障可用）。
$e = Schedule::call($taskGate('comments_download', function () {
    $cfg = (array) app(SettingsService::class)->get(SettingKey::COMMENT_DOWNLOAD_CONFIG);

    // 智能模式（auto_update）：按百分比抽取最久未更新的视频，按时间衰减自适应刷新
    $args = ['--sleep' => (int) ($cfg['sleep'] ?? 0)];
    if ($cfg['auto_update'] ?? true) {
        $args['--auto-update'] = true;
        $args['--percent'] = (int) ($cfg['percent'] ?? 3);
    }
    if ((int) ($cfg['incremental'] ?? 0) > 0) {
        $args['--incremental'] = (int) $cfg['incremental'];
    }
    if ((int) ($cfg['limit'] ?? 0) > 0) {
        $args['--limit'] = (int) $cfg['limit'];
    }
    if ((int) ($cfg['max_videos'] ?? 0) > 0) {
        $args['--max-videos'] = (int) $cfg['max_videos'];
    }
    if ((int) ($cfg['interval'] ?? 0) > 0) {
        $args['--interval'] = (int) $cfg['interval'];
    }

    Artisan::call('app:download-all-comments', $args);
}));
$task('comments_download')->apply($e, 'comments_download');

// ==========================================================================
// 12. 处理下载队列
// ==========================================================================
// 每分钟从下载队列取出任务并派发 Job（关闭该任务后新下载请求将滞留在队列中）
$e = Schedule::call($taskGate('download_queue', function () {
    Artisan::call('app:process-download-queue');
}));
$task('download_queue')->apply($e, 'download_queue');
$e->withoutOverlapping();

// ==========================================================================
// 13. 系统空间与数据库使用情况全量统计
// ==========================================================================
// 作用：全量统计数据库各表行数及文件夹物理大小，解决实时获取极度缓慢的问题。
$e = Schedule::call($taskGate('system_stats', function () {
    Artisan::call('app:calculate-system-stats');
}));
$task('system_stats')->apply($e, 'system_stats');
$e->withoutOverlapping();

// ==========================================================================
// 14. 自动清理并限制系统日志大小
// ==========================================================================
// 作用：防止 laravel.log 文件无限增长撑爆磁盘。如果文件超过 50MB，则只保留最新的 5000 行。
$e = Schedule::call($taskGate('rotate_system_logs', function () {
    $logPath = storage_path('logs/laravel.log');

    // 如果日志文件存在，且大小超过 50MB (50 * 1024 * 1024 bytes)
    if (file_exists($logPath) && filesize($logPath) > 52428800) {
        $tmpPath = $logPath.'.tmp';
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
}));
$task('rotate_system_logs')->apply($e, 'rotate_system_logs');

// ==========================================================================
// 15. 每日平滑更新视频播放量 (防风控)
// ==========================================================================
// 作用：每天抽取最久未更新的 3% 的视频放入队列，任务之间强制间隔 5 秒执行，
//      既能保持播放量数据鲜活，又能避免队列进程瞬间并发打满被 B 站封禁。
$e = Schedule::call($taskGate('video_stats', function () {
    Artisan::call('app:update-video-stats', ['--auto-update' => true, '--percent' => 3, '--sleep' => 5]);
}));
$task('video_stats')->apply($e, 'video_stats');
$e->withoutOverlapping();

// 每天修复视频冻结状态，只有冻结且无效的视频才修复
$e = Schedule::call($taskGate('freeze_fix', function () {
    Artisan::call('app:fix-freeze-status');
}));
$task('freeze_fix')->apply($e, 'freeze_fix');
$e->withoutOverlapping();

// ==========================================================================
// 16. SQLite 数据库轻量维护（统计信息更新）
// ==========================================================================
// 作用：执行 PRAGMA optimize，让 SQLite 查询优化器获得最新的表统计信息，
//      避免千万级数据（评论/弹幕）下查询计划劣化。该操作轻量且自动跳过不需要 ANALYZE 的表。
$e = Schedule::call($taskGate('database_analyze', function () {
    Artisan::call('app:database-maintenance', ['--analyze' => true]);
}));
$task('database_analyze')->apply($e, 'database_analyze');
$e->withoutOverlapping();

// ==========================================================================
// 17. SQLite 数据库深度维护（VACUUM 空间回收）
// ==========================================================================
// 作用：WAL checkpoint + VACUUM 回收数据库空闲页 + ANALYZE。
//      大库（数 GB 级）执行需要几分钟，期间写入会被短暂阻塞，队列任务具备重试能力，
//      因此默认安排在每周日凌晨低峰期。
$e = Schedule::call($taskGate('database_vacuum', function () {
    Artisan::call('app:database-maintenance', ['--vacuum' => true]);
}));
$task('database_vacuum')->apply($e, 'database_vacuum');
$e->withoutOverlapping();
