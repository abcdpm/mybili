<?php

namespace App\Services;

use App\Models\Danmaku;
use App\Models\FavoriteList;
use App\Models\Video;
use App\Models\VideoPart;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use PDO;

class SystemService
{
    public function getSystemInfo(): array
    {
        $info = [
            'app_version'      => config('app.version'),
            'php_version'      => phpversion(),
            'laravel_version'  => app()->version(),
            'database_version' => DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            'timezone'         => config('app.timezone'),
            'time_now'         => Carbon::now()->toDateTimeString(),

            // usage：不再实时 count()，O(1) 极速读取缓存
            'database_usage'   => [
                'favorite_lists' => Cache::get('stat_favorite_lists', 0),
                'videos'         => Cache::get('stat_videos', 0),
                'video_parts'    => Cache::get('stat_video_parts', 0),
                'danmaku'        => Cache::get('stat_danmaku', 0),
                'comments'       => Cache::get('stat_comments', 0), // 新增评论数量
                'emotes'         => Cache::get('stat_emotes', 0),   // 新增表情包数量
                'db_size'        => $this->formatBytes(Cache::get('stat_db_size', 0)),
            ],
            // 读取缓存的 Byte 字节数并格式化
            'media_usage'      => [
                'videos_size' => $this->formatBytes(Cache::get('stat_videos_size', 0)),
                'images_size' => $this->formatBytes(Cache::get('stat_images_size', 0)),
            ],
        ];
        return $info;
    }

    /**
     * 将 Byte 转换为人类可读的容量格式
     */
    private function formatBytes(int $bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
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
