<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Cover;
use App\Models\Emote;
use App\Traits\ImageOptimizerTrait;
use Illuminate\Support\Facades\Storage;

class OptimizeImages extends Command
{
    use ImageOptimizerTrait;

    protected $signature = 'app:optimize-images
                            {--force : 强制重新优化所有图片（即使已经是 webp 或 avif 格式）}
                            {--status : 查看当前图片优化进度统计}
                            {--cover-id= : 指定要重新优化的封面 ID}
                            {--emote-id= : 指定要重新优化的表情包 ID}';
                            
    protected $description = '批量优化全站图片 (封面、表情包、评论区头像、评论配图) 为节省体积的格式 (WebP/AVIF)';

    public function handle(): void
    {
        if ($this->option('status')) {
            $this->showStatus();
            return;
        }

        $force = $this->option('force');
        $coverId = $this->option('cover-id');
        $emoteId = $this->option('emote-id');

        if ($coverId) {
            $this->optimizeCovers(true, $coverId);
            return;
        }

        if ($emoteId) {
            $this->optimizeEmotes(true, $emoteId);
            return;
        }

        $this->info("==== 1. 开始优化封面图片 (Covers) ====");
        $this->optimizeCovers($force);

        $this->info("==== 2. 开始优化表情包 (Emotes) ====");
        $this->optimizeEmotes($force);

        $this->info("==== 3. 开始优化评论区配图 (Comments) ====");
        $this->optimizeDirectory('comments', $force);

        $this->info("==== 优化完成！ ====");
        $this->line("所有原图均已保留，前端代理将自动优先使用新的 AVIF/WebP 格式。");
    }

    /**
     * 【全新新增】基于物理目录的静默优化（适用于头像和评论配图）
     * 依靠智能代理路由，无需修改数据库
     */
    private function optimizeDirectory(string $folder, bool $force)
    {
        $disk = Storage::disk('public');
        $files = $disk->files($folder);
        
        $toProcess = [];
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            // 找出所有传统的 jpg/png 图片
            if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $base = pathinfo($file, PATHINFO_FILENAME);
                
                // 如果不是强制模式，且已经存在同名的 avif 或 webp，则跳过
                if (!$force) {
                    if ($disk->exists("{$folder}/{$base}.avif") || $disk->exists("{$folder}/{$base}.webp")) {
                        continue;
                    }
                }
                $toProcess[] = $disk->path($file);
            }
        }

        $total = count($toProcess);
        if ($total === 0) {
            $this->info("目录 {$folder} 中没有需要优化的新图片。");
            $this->newLine();
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($toProcess as $absolutePath) {
            // 调用 Trait 中的方法进行优化。0字节保护和动图跳过逻辑都会自动生效。
            $this->optimizeImage($absolutePath);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * 优化封面图片 (保持数据库更新逻辑)
     */
    private function optimizeCovers(bool $force, $targetId = null)
    {
        $query = Cover::query();
        if ($targetId) {
            $query->where('id', $targetId);
        } else if (!$force) {
            $query->where('filename', 'not like', '%.webp')
                  ->where('filename', 'not like', '%.avif');
        }

        $covers = $query->get();
        if ($covers->count() === 0) {
            $this->info("没有找到符合条件的封面图片。");
            $this->newLine();
            return;
        }

        $bar = $this->output->createProgressBar($covers->count());
        foreach ($covers as $cover) {
            $absolutePath = storage_path('app/public/' . $cover->path);
            if ($targetId || $force) {
                 $baseName = pathinfo($absolutePath, PATHINFO_FILENAME);
                 $dir = dirname($absolutePath);
                 foreach (['.jpg', '.png'] as $ext) {
                     if (file_exists($dir . '/' . $baseName . $ext)) {
                         $absolutePath = $dir . '/' . $baseName . $ext; break;
                     }
                 }
            }
            if (!file_exists($absolutePath)) $absolutePath = storage_path('app/public/images/' . basename($cover->filename));

            if (file_exists($absolutePath)) {
                $newPath = $this->optimizeImage($absolutePath);
                if ($newPath !== $absolutePath && file_exists($newPath) && filesize($newPath) > 0) {
                    $cover->update([
                        'filename'  => basename($newPath),
                        'path'      => 'images/' . basename($newPath),
                        'mime_type' => @getimagesize($newPath)['mime'] ?? 'image/webp',
                        'size'      => filesize($newPath)
                    ]);
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
    }

    /**
     * 优化表情包 (保持数据库更新逻辑)
     */
    private function optimizeEmotes(bool $force, $targetId = null)
    {
        $query = Emote::query();
        if ($targetId) {
            $query->where('id', $targetId);
        } else if (!$force) {
            $query->where('filename', 'not like', '%.webp')->where('filename', 'not like', '%.avif');
        }

        $emotes = $query->get();
        if ($emotes->count() === 0) {
            $this->info("没有找到符合条件的表情包图片。");
            $this->newLine();
            return;
        }

        $bar = $this->output->createProgressBar($emotes->count());
        foreach ($emotes as $emote) {
            $absolutePath = storage_path('app/public/emotes/' . $emote->filename);
            if ($targetId || $force) {
                 $baseName = pathinfo($absolutePath, PATHINFO_FILENAME);
                 $dir = dirname($absolutePath);
                 foreach (['.jpg', '.png', '.gif'] as $ext) {
                     if (file_exists($dir . '/' . $baseName . $ext)) {
                         $absolutePath = $dir . '/' . $baseName . $ext; break;
                     }
                 }
            }

            if (file_exists($absolutePath)) {
                $newPath = $this->optimizeImage($absolutePath);
                if ($newPath !== $absolutePath && file_exists($newPath) && filesize($newPath) > 0) {
                    $emote->update(['filename' => basename($newPath)]);
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
    }

    /**
     * 显示统计信息
     */
    protected function showStatus()
    {
        // 统计封面
        $totalCovers = Cover::count();
        $optimizedCovers = Cover::where('filename', 'like', '%.webp')
                                ->orWhere('filename', 'like', '%.avif')
                                ->count();
        $pendingCovers = $totalCovers - $optimizedCovers;
        $coverPercent = $totalCovers > 0 ? round(($optimizedCovers / $totalCovers) * 100, 2) : 0;

        // 统计表情包
        $totalEmotes = Emote::count();
        $optimizedEmotes = Emote::where('filename', 'like', '%.webp')
                                ->orWhere('filename', 'like', '%.avif')
                                ->count();
        $pendingEmotes = $totalEmotes - $optimizedEmotes;
        $emotePercent = $totalEmotes > 0 ? round(($optimizedEmotes / $totalEmotes) * 100, 2) : 0;

        // 汇总
        $totalAll = $totalCovers + $totalEmotes;
        $optimizedAll = $optimizedCovers + $optimizedEmotes;
        $pendingAll = $totalAll - $optimizedAll;
        $totalPercent = $totalAll > 0 ? round(($optimizedAll / $totalAll) * 100, 2) : 0;

        $this->table(
            ['模块', '图片总数', '已优化', '待优化', '覆盖进度'],
            [
                ['封面图片 (Covers)', $totalCovers, $optimizedCovers, $pendingCovers, "{$coverPercent}%"],
                ['表情包 (Emotes)', $totalEmotes, $optimizedEmotes, $pendingEmotes, "{$emotePercent}%"],
                ['总计', $totalAll, $optimizedAll, $pendingAll, "{$totalPercent}%"],
            ]
        );
        
        $this->info("提示：动图(GIF/APNG)虽然不改变后缀，但系统遇到会自动跳过，因此如果有些进度达不到 100% 可能是由于存在动图。");
    }
}