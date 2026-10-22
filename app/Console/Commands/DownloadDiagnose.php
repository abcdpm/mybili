<?php

namespace App\Console\Commands;

use App\Models\DownloadQueue;
use App\Models\VideoPart;
use Illuminate\Console\Command;

/**
 * 下载遗留问题诊断与安全处理。
 *
 * 解决"视频下载页有一些历史遗留的无法下载完成的视频"：
 * 已在 B 站失效的视频（invalid=1 或标题"已失效视频"）永远无法下载完成，
 * 旧逻辑会反复重新入队形成空转。本命令：
 *   1. 分类报告（只读）：失效遗留分P / 队列滞留 / 失效视频队列残留 / 正常待下载；
 *   2. 可选处理（不删除任何媒体文件与业务数据）：
 *      --prune-invalid：把失效视频的 pending/running 队列行标记为 cancelled；
 *      --reset-stale  ：把长时间卡在 running 的队列行重置为 pending（安全重试）。
 *
 * 默认只报告不修改，生产环境可先 dry-run 观察再处理。
 */
class DownloadDiagnose extends Command
{
    protected $signature = 'app:download-diagnose
                                {--prune-invalid : 将失效视频的滞留队列行标记为 cancelled（不删除任何数据）}
                                {--reset-stale : 将长时间 running 的队列行重置为 pending}
                                {--stale-hours=6 : running 滞留判定小时数}';

    protected $description = '诊断历史遗留的无法下载完成的视频（默认只报告，处理需显式指定选项）';

    public function handle(): int
    {
        $staleHours = (int) $this->option('stale-hours');

        // 1. 失效视频遗留分P：有分P缺文件且视频已失效 —— 永远无法下载完成（历史遗留主因）
        $invalidStuckParts = VideoPart::query()
            ->whereNull('video_download_path')
            ->whereHas('video', fn ($q) => $q->where('invalid', 1))
            ->count();

        // 2. 正常待下载分P：视频有效但缺文件 —— 可通过队列重试完成
        $pendingParts = VideoPart::query()
            ->whereNull('video_download_path')
            ->whereHas('video', fn ($q) => $q->where('invalid', 0))
            ->count();

        // 3. 队列滞留：running 超过阈值（工作进程中断/崩溃残留）
        $staleRunning = DownloadQueue::where('status', DownloadQueue::STATUS_RUNNING)
            ->where('updated_at', '<', now()->subHours($staleHours))
            ->count();

        // 4. 失效视频的队列残留（pending/running）—— 空转源头
        $invalidQueueRows = DownloadQueue::whereIn('status', [DownloadQueue::STATUS_PENDING, DownloadQueue::STATUS_RUNNING])
            ->where('type', DownloadQueue::TYPE_VIDEO)
            ->whereHas('videoPart.video', fn ($q) => $q->where('invalid', 1))
            ->count();

        $this->info('=== 下载遗留诊断报告 ===');
        $this->line("失效视频遗留分P（永远无法完成，建议接受跳过） : {$invalidStuckParts}");
        $this->line("有效视频待下载分P（可重试）                   : {$pendingParts}");
        $this->line("队列滞留 running（>{$staleHours}h）               : {$staleRunning}");
        $this->line("失效视频队列残留（pending/running 空转）      : {$invalidQueueRows}");

        if ($this->option('prune-invalid')) {
            $updated = DownloadQueue::whereIn('status', [DownloadQueue::STATUS_PENDING, DownloadQueue::STATUS_RUNNING])
                ->where('type', DownloadQueue::TYPE_VIDEO)
                ->whereHas('videoPart.video', fn ($q) => $q->where('invalid', 1))
                ->update([
                    'status' => DownloadQueue::STATUS_CANCELLED,
                    'completed_at' => now(),
                    'error_msg' => '已失效视频，诊断命令自动取消（历史遗留清理）',
                ]);
            $this->info("已将 {$updated} 条失效视频队列行标记为 cancelled（未删除任何媒体/业务数据）。");
        }

        if ($this->option('reset-stale')) {
            $reset = DownloadQueue::where('status', DownloadQueue::STATUS_RUNNING)
                ->where('updated_at', '<', now()->subHours($staleHours))
                ->update(['status' => DownloadQueue::STATUS_PENDING]);
            $this->info("已将 {$reset} 条滞留 running 的队列行重置为 pending，等待重试。");
        }

        if (! $this->option('prune-invalid') && ! $this->option('reset-stale')) {
            $this->line('');
            $this->line('提示：当前为只读报告。可使用 --prune-invalid / --reset-stale 进行安全处理（不删除任何数据）。');
        }

        return 0;
    }
}
