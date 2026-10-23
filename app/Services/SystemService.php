<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;

class SystemService
{
    public function getSystemInfo(): array
    {
        $info = [
            'app_version' => config('app.version'),
            // PHP 版本：Web 运行时（FrankenPHP 内嵌 PHP）与 CLI/任务运行时（镜像 PHP）可能不同版本，
            // 两个都如实上报；php_version 保持向后兼容 = Web 运行时版本，php_cli_version 为 CLI/队列任务版本。
            'php_version' => phpversion(),
            'php_sapi' => php_sapi_name(),
            'php_cli_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'database_version' => DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            'timezone' => config('app.timezone'),
            'time_now' => Carbon::now()->toDateTimeString(),

            // usage：不再实时 count()，O(1) 极速读取缓存
            'database_usage' => [
                'favorite_lists' => Cache::get('stat_favorite_lists', 0),
                'videos' => Cache::get('stat_videos', 0),
                'video_parts' => Cache::get('stat_video_parts', 0),
                'danmaku' => Cache::get('stat_danmaku', 0),
                'comments' => Cache::get('stat_comments', 0), // 新增评论数量
                'emotes' => Cache::get('stat_emotes', 0),   // 新增表情包数量
                'db_size' => $this->formatBytes($this->liveDatabaseSize()),
            ],
            // 读取缓存的 Byte 字节数并格式化
            'media_usage' => [
                'videos_size' => $this->formatBytes(Cache::get('stat_videos_size', 0)),
                'images_size' => $this->formatBytes(Cache::get('stat_images_size', 0)),
            ],
        ];

        return $info;
    }

    /**
     * 将 Byte 转换为人类可读的容量格式
     */
    /**
     * 实时计算数据库文件大小（O(1)，SQLite PRAGMA page_count × page_size）。
     *
     * 不依赖每日统计缓存：旧行为读 stat_db_size 缓存，值在下次 calculate-system-stats
     * 运行前一直是旧算法结果（低估约 76 倍）甚至为 0，导致"数据库大小不准确"。
     * PRAGMA 为常数级元数据读取，每次请求实时取也不会有性能负担；
     * 非 SQLite 驱动时回退文件大小，最后回退缓存值。
     */
    private function liveDatabaseSize(): int
    {
        try {
            $pageCount = (int) (DB::selectOne('PRAGMA page_count')?->page_count ?? 0);
            $pageSize = (int) (DB::selectOne('PRAGMA page_size')?->page_size ?? 0);
            if ($pageCount > 0 && $pageSize > 0) {
                return $pageCount * $pageSize;
            }
        } catch (\Throwable) {
            // 非 SQLite 驱动或 PRAGMA 受限：走下面的兜底
        }

        $databasePath = database_path('database.sqlite');
        if (file_exists($databasePath)) {
            return (int) filesize($databasePath);
        }

        return (int) Cache::get('stat_db_size', 0);
    }

    private function formatBytes(int $bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }

    // public function getDatabaseSize(): int
    // {
    //     // 如果是sqlite
    //     if (DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) == 'sqlite') {
    //         return DB::table('sqlite_master')->where('type', 'table')->sum('rootpage') * 1024;
    //     }
    //     return 0;
    // }

    // public function getMediaSize(string $directory): string
    // {
    //     $mediasPath = storage_path("app/public/".$directory);
    //     exec(sprintf('du -sh %s', escapeshellarg($mediasPath)), $output, $result);
    //     if ($result !== 0) {
    //         return '0';
    //     }
    //     $output = explode("\t", $output[0]);
    //     return $output[0] ?? '0';
    // }
}
