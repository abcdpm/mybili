<?php

namespace App\Console\Commands;

use App\Models\AudioPart;
use App\Models\Comment;
use App\Models\Danmaku;
use App\Models\DanmakuFile;
use App\Models\Video;
use App\Models\VideoPart;
use App\Services\VideoManager\VideoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 清理历史遗留的孤儿数据。
 *
 * 背景：旧版本「永久删除」实为软删除，且 danmaku / video_parts /
 * favorite_list_videos / subscription_videos 均无外键，长期运行后会积累：
 *   1. 软删除视频（及其评论/弹幕/分P）；
 *   2. video_id 指向已不存在视频的孤儿行。
 *
 * 安全性：默认仅报告数量，不做任何删除；加 --force 才真正清理。
 * 可反复执行（幂等）。
 */
class CleanupOrphanData extends Command
{
    protected $signature = 'app:cleanup-orphan-data
        {--force : 真正执行清理（不加此参数仅报告数量）}';

    protected $description = '清理历史遗留的软删除视频与孤儿关联数据（默认仅报告，--force 才删除）';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        // ====== 1. 软删除视频 ======
        $trashedVideos = Video::onlyTrashed()->select(['id', 'title'])->get();
        $this->info(sprintf('软删除视频: %d 个', $trashedVideos->count()));
        if ($trashedVideos->isNotEmpty()) {
            $this->line('  示例ID: ' . $trashedVideos->take(10)->pluck('id')->implode(', '));
        }

        // ====== 2. 真孤儿行（video_id 在 videos 表中不存在）======
        // 使用子查询，避免大表 ID 列表超出 SQLite 绑定变量上限
        $orphanComments   = Comment::query()->whereNotIn('video_id', $this->existingVideoIds())->count();
        $orphanParts      = VideoPart::query()->whereNotIn('video_id', $this->existingVideoIds())->count();
        $orphanAudioParts = AudioPart::query()->whereNotIn('video_id', $this->existingVideoIds())->count();
        $orphanFavPivot   = DB::table('favorite_list_videos')->whereNotIn('video_id', $this->existingVideoIds())->count();
        $orphanSubPivot   = DB::table('subscription_videos')->whereNotIn('video_id', $this->existingVideoIds())->count();
        $orphanDanmaku    = Danmaku::query()->whereNotNull('video_id')->whereNotIn('video_id', $this->existingVideoIds())->count();

        $this->table(['孤儿数据类型', '数量'], [
            ['评论（comments）', $orphanComments],
            ['分P（video_parts）', $orphanParts],
            ['音频（audio_parts）', $orphanAudioParts],
            ['收藏夹关联（favorite_list_videos）', $orphanFavPivot],
            ['订阅关联（subscription_videos）', $orphanSubPivot],
            ['弹幕行（danmaku, 按 video_id）', $orphanDanmaku],
        ]);

        if (! $force) {
            $this->warn('当前为报告模式，未删除任何数据。确认无误后请加 --force 执行清理。');

            return self::SUCCESS;
        }

        // ====== 3. 执行清理 ======
        // 3.1 软删除视频：完整清理其关联数据（含评论/弹幕文件/分P/关联表）后强制删除
        $purged = 0;
        foreach ($trashedVideos as $video) {
            $model = Video::withTrashed()->find($video->id);
            if (! $model) {
                continue;
            }
            app(VideoService::class)->purgeVideoData($model);
            $model->forceDelete();
            $purged++;
        }
        $this->info(sprintf('已清理软删除视频: %d 个', $purged));

        // 3.2 真孤儿行删除
        $this->info('开始清理孤儿关联行...');
        $deletedComments = Comment::query()->whereNotIn('video_id', $this->existingVideoIds())->delete();
        $deletedParts    = VideoPart::query()->whereNotIn('video_id', $this->existingVideoIds())->delete();
        AudioPart::query()->whereNotIn('video_id', $this->existingVideoIds())->delete();
        DB::table('favorite_list_videos')->whereNotIn('video_id', $this->existingVideoIds())->delete();
        DB::table('subscription_videos')->whereNotIn('video_id', $this->existingVideoIds())->delete();
        Danmaku::query()->whereNotNull('video_id')->whereNotIn('video_id', $this->existingVideoIds())->delete();
        DanmakuFile::query()->whereNotNull('video_id')->whereNotIn('video_id', $this->existingVideoIds())->delete();

        $this->info(sprintf('清理完成：评论 %d 条、分P %d 条。', $deletedComments, $deletedParts));
        $this->line('提示：如需回收数据库磁盘空间，请运行 php artisan app:database-maintenance --vacuum。');

        return self::SUCCESS;
    }

    /**
     * 现存视频 ID 子查询（含软删除，孤儿判定以"完全不存在"为准）。
     */
    private function existingVideoIds(): \Illuminate\Database\Query\Builder
    {
        return DB::table('videos')->select('id');
    }
}