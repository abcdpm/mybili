<?php

namespace App\Console\Commands;

use App\Models\Comment;
use App\Models\Danmaku;
use App\Models\Emote;
use App\Models\FavoriteList;
use App\Models\Video;
use App\Models\VideoPart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;

class CalculateSystemStats extends Command
{
    protected $signature = 'app:calculate-system-stats';

    protected $description = '全量计算系统数据库与媒体文件使用情况并存入缓存';

    public function handle()
    {
        $this->info('开始全量统计系统数据...');

        // 1. 统计并持久化数量 (包含新增的评论和表情包)
        // 弹幕数量 = 数据库遗留行（老数据）+ 文件化弹幕（danmaku_files 索引）
        Cache::forever('stat_favorite_lists', FavoriteList::count());
        Cache::forever('stat_videos', Video::count());
        Cache::forever('stat_video_parts', VideoPart::count());
        Cache::forever('stat_danmaku', Danmaku::count() + app(\App\Services\Danmaku\DanmakuFileStore::class)->totalFileCount());
        Cache::forever('stat_comments', Comment::count());
        Cache::forever('stat_emotes', Emote::count());

        // 2. 统计数据库文件大小
        Cache::forever('stat_db_size', $this->getDatabaseSize());

        // 3. 统计文件系统大小（这里使用 -sb 获取精确的 Byte 字节数，方便后续做增量加法）
        Cache::forever('stat_videos_size', $this->getMediaSizeBytes('videos'));
        // 只要表情包、头像、评论图片、视频封面都存储在 images 目录下，此命令即可全部覆盖
        Cache::forever('stat_images_size', $this->getMediaSizeBytes('images'));

        $this->info('系统数据全量统计完成！');
    }

    /**
     * 计算数据库文件的精确字节大小。
     *
     * 旧实现 `SUM(rootpage) × 1024` 有两个错误：
     * ① rootpage 的单位是"页"而非 KB；② SQLite 默认页大小为 4096 而非 1024 —— 实测低估约 76 倍。
     * 正确做法：PRAGMA page_count × page_size（等价于文件实际占用），失败时回退文件系统大小。
     */
    private function getDatabaseSize(): int
    {
        if (DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) == 'sqlite') {
            try {
                $pageCount = (int) (DB::selectOne('PRAGMA page_count')?->page_count ?? 0);
                $pageSize = (int) (DB::selectOne('PRAGMA page_size')?->page_size ?? 0);
                if ($pageCount > 0 && $pageSize > 0) {
                    return $pageCount * $pageSize;
                }
            } catch (\Throwable) {
                // PRAGMA 不可用时走文件兜底
            }
        }
        $databasePath = database_path('database.sqlite');

        return file_exists($databasePath) ? (int) filesize($databasePath) : 0;
    }

    private function getMediaSizeBytes(string $directory): int
    {
        $mediasPath = storage_path('app/public/'.$directory);
        if (! file_exists($mediasPath)) {
            return 0;
        }
        // 使用 -sb 返回精确的 byte 大小，而非人类可读格式
        exec(sprintf('du -sb %s', escapeshellarg($mediasPath)), $output, $result);
        if ($result !== 0 || empty($output)) {
            return 0;
        }

        return (int) explode("\t", $output[0])[0];
    }
}
