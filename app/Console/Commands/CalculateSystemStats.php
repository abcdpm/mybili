<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Video;
use App\Models\VideoPart;
use App\Models\Danmaku;
use App\Models\Comment;
use App\Models\Emote;
use App\Models\FavoriteList;
use PDO;

class CalculateSystemStats extends Command
{
    protected $signature = 'app:calculate-system-stats';
    protected $description = '全量计算系统数据库与媒体文件使用情况并存入缓存';

    public function handle()
    {
        $this->info('开始全量统计系统数据...');

        // 1. 统计并持久化数量 (包含新增的评论和表情包)
        Cache::forever('stat_favorite_lists', FavoriteList::count());
        Cache::forever('stat_videos', Video::count());
        Cache::forever('stat_video_parts', VideoPart::count());
        Cache::forever('stat_danmaku', Danmaku::count());
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

    private function getDatabaseSize(): int
    {
        if (DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) == 'sqlite') {
            return (int) DB::table('sqlite_master')->where('type', 'table')->sum('rootpage') * 1024;
        }
        return 0;
    }

    private function getMediaSizeBytes(string $directory): int
    {
        $mediasPath = storage_path("app/public/" . $directory);
        if (!file_exists($mediasPath)) {
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