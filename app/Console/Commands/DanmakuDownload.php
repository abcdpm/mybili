<?php
namespace App\Console\Commands;

use App\Jobs\DownloadDanmakuJob;
use App\Models\VideoPart;
use App\Models\Danmaku; // 引入弹幕模型用于统计总条数
use Illuminate\Console\Command;

class DanmakuDownload extends Command
{
    /**
     * 签名中新增了 --status 选项
     *
     * @var string
     */
    protected $signature = 'app:danmaku-download 
                            {video_id? : 指定视频ID (仅处理该视频下的所有分P)} 
                            {--force : 强制重新下载所有视频的弹幕（忽略已下载状态）}
                            {--status : 查看当前弹幕下载进度统计 (不执行任务)}';

    protected $description = '扫描并批量投递弹幕下载任务';

    public function handle()
    {
        // 如果用户传入了 --status，则仅展示统计面板并退出
        if ($this->option('status')) {
            $this->showStatus();
            return;
        }

        $videoId = $this->argument('video_id');
        $force = $this->option('force');

        $query = VideoPart::query();

        if ($videoId) {
            // 如果指定了具体视频，查询该视频下的所有分P
            $query->where('video_id', $videoId);
            $this->info("模式: 仅处理视频 ID {$videoId} 的弹幕");
        } else {
            if (!$force) {
                // 缺省状态：只找出没有弹幕的时间戳记录，防止重复下载
                $query->whereNull('danmaku_downloaded_at');
                $this->info("模式: 扫描并自动补全缺失弹幕的视频分P");
            } else {
                $this->info("模式: 强制重新下载库中所有视频的弹幕");
            }
        }

        $total = $query->count();

        if ($total == 0) {
            $this->info("🎉 没有发现需要处理的视频分P。");
            return;
        }

        $this->info("共找到 {$total} 个需要下载弹幕的视频分P，准备投递...");

        // 创建进度条
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // 采用 chunkById 分块处理，防止内存溢出
        $query->chunkById(100, function ($videoParts) use ($bar) {
            foreach ($videoParts as $part) {
                // 投递标准的下载任务到 slow/default 队列
                dispatch(new DownloadDanmakuJob($part));
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("✅ 所有弹幕下载任务已成功投递至队列，请在 Horizon 中查看进度。");
    }

    /**
     * 展示弹幕下载进度统计面板
     */
    protected function showStatus()
    {
        $this->info("正在实时计算弹幕库状态...");

        // 统计各项数据
        $totalParts = VideoPart::count();
        $downloadedParts = VideoPart::whereNotNull('danmaku_downloaded_at')->count();
        $pendingParts = $totalParts - $downloadedParts;
        $totalDanmaku = Danmaku::count();

        // 计算覆盖率百分比
        $percent = $totalParts > 0 ? round(($downloadedParts / $totalParts) * 100, 2) : 0;

        // 打印表格
        $this->table(
            ['统计项', '数值'],
            [
                ['系统收录视频分P总数', $totalParts],
                ['已下载弹幕的分P数', $downloadedParts],
                ['待补全弹幕的分P数', $pendingParts],
                ['数据库弹幕总条数', number_format($totalDanmaku)], // 格式化数字更易读
                ['弹幕覆盖率进度', "{$percent}%"],
            ]
        );
        
        $this->info("注意：此统计基于数据库当前状态。");
    }
}