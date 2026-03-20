<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Jobs\PullVideoInfoJob;
use Illuminate\Console\Command;

class UpdateVideoStats extends Command
{
    protected $signature = 'app:update-video-stats 
                            {video_id? : 指定视频ID (Video ID)} 
                            {--force : 强制重新拉取所有有效视频的信息}
                            {--status : 查看当前视频播放量获取进度统计 (不执行任务)}';
                            
    protected $description = '快速投递队列：定期更新或修复存量视频的播放量和时长数据';

    public function handle(): void
    {
        // 状态查看模式
        if ($this->option('status')) {
            $this->showStatus();
            return;
        }

        $videoId = $this->argument('video_id');
        $force   = $this->option('force');

        $this->info("开始扫描数据库中的存量视频...");

        // 基础查询：仅处理未失效的有效视频
        $query = Video::where('invalid', 0);

        // 指定单个视频逻辑
        if ($videoId) {
            $query->where('id', $videoId);
            $this->info("模式: 仅处理视频 ID {$videoId}");
        }

        // 默认模式：仅修复缺失数据的视频；如果加了 --force 则是全量刷新
        if (!$force && !$videoId) {
            $query->where(function($q) {
                // 查找播放量为0 或 时长为0的视频
                $q->where('view', 0)->orWhere('duration', 0);
            });
            $this->info("模式: 增量修复 (仅处理播放量或时长为 0 的视频)。");
            $this->comment("提示: 如需定期全量刷新所有视频最新播放量，请加上 --force 参数");
        } else if ($force) {
            $this->info("模式: 强制更新 (全量刷新所有有效视频的最新播放量)");
        }

        $count = $query->count();
        if ($count === 0) {
            $this->info("没有发现符合条件的视频，无需更新。");
            return;
        }
        $this->info("待处理视频总数: {$count}");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        // 核心提速点：使用 chunkById 极大降低数据库内存占用，并以最快速度投递到队列
        $query->chunkById(500, function ($videos) use ($bar) {
            foreach ($videos as $video) {
                // 利用现有的限流队列，防止被 B站 API 封禁
                dispatch(new PullVideoInfoJob($video->bvid));
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("所有任务已光速推送到队列！请通过 Horizon 查看后台实际拉取进度。");
    }

    // 显示统计信息的方法
    protected function showStatus(): void
    {
        $total = Video::where('invalid', 0)->count();
        
        // 统计已经获取到播放量（view > 0）的视频
        $done = Video::where('invalid', 0)
                     ->where('view', '>', 0)
                     ->count();
        
        $pending = $total - $done;
        $percent = $total > 0 ? round(($done / $total) * 100, 2) : 0;

        $this->table(
            ['统计项', '数值'],
            [
                ['有效视频总数', $total],
                ['已获取播放量/时长', $done],
                ['缺失数据 (待修复)', $pending],
                ['数据完整度', "{$percent}%"],
            ]
        );
        
        $this->info("注意：后台队列处理需要时间，'已获取' 的数量会随着 Horizon 处理逐渐上升。");
    }
}