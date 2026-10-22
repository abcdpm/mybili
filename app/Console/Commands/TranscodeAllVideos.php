<?php

namespace App\Console\Commands;

use App\Enums\SettingKey;
use App\Jobs\TranscodeVideoJob;
use App\Models\VideoPart;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class TranscodeAllVideos extends Command
{
    // [修改] 增加 video_id 参数
    // [修改] 增加 status 参数
    // [修改] 增加 hwaccel 参数 (cpu软解, Intel qsv, Nvidia nvenc)
    protected $signature = 'app:transcode-all 
                            {video_id? : 指定视频ID (Video ID)} 
                            {--force : 强制重新转码}
                            {--hwaccel=cpu : 指定转码模式 [cpu, qsv, nvenc]}
                            {--status : 查看当前转码进度统计 (不执行任务)}';

    protected $description = '将所有已下载视频转码为移动端兼容格式';

    public function handle()
    {
        // 检查系统设置：是否允许视频转码
        $transcodeEnabled = app(SettingsService::class)->get(SettingKey::TRANSCODE_VIDEO_ENABLED) ?? 'off';
        if ($transcodeEnabled !== 'on') {
            $this->info('[视频转码] 系统设置已关闭视频转码功能，跳过执行');

            return;
        }

        // 状态查看模式
        if ($this->option('status')) {
            $this->showStatus();

            return;
        }

        $videoId = $this->argument('video_id');
        $force = $this->option('force');

        // 加速模式：命令行 --hwaccel 优先，否则读"转码设置"（transcode_config.hwaccel），
        // auto 时按设备注入情况自动检测（NVIDIA / Intel VAAPI / 纯 CPU）
        $mode = strtolower($this->option('hwaccel') ?: 'auto');
        if ($mode === 'auto') {
            $configured = strtolower((string) (((array) app(\App\Services\SettingsService::class)->get(\App\Enums\SettingKey::TRANSCODE_CONFIG))['hwaccel'] ?? 'auto'));
            $mode = $configured === 'auto' ? $this->detectMode() : $configured;
        }

        // 简单校验
        if (! in_array($mode, ['cpu', 'qsv', 'nvenc'])) {
            $this->error("无效的加速模式: {$mode}。请使用 cpu, qsv 或 nvenc");
            $this->comment('CPU 软解 / Intel QSV / Nvidia nvenc');

            return;
        }

        if ($mode === 'qsv') {
            $this->info('模式: 启用 Intel QSV 硬件加速');
            $this->comment('注意：确保 Docker 容器已映射 /dev/dri 设备');
        } elseif ($mode === 'nvenc') {
            $this->info('模式: 启用 Nvidia NVENC 硬件加速');
            $this->comment('注意：确保 Docker 容器已配置 NVIDIA Runtime');
        } else {
            $this->info('模式: 使用 CPU 软件转码');
        }

        $this->info('开始扫描存量视频...');

        // 基础查询：必须是已下载的视频分P
        $query = VideoPart::whereNotNull('video_download_path');

        // 指定视频逻辑
        if ($videoId) {
            // 假设 VideoPart 表中有 video_id 字段关联到 Video 表
            $query->where('video_id', $videoId);
            $this->info("模式: 仅处理视频 ID {$videoId} 下的分P");
        }

        // 默认跳过已转码视频 (mobile_download_path 不为空)
        if (! $force) {
            $query->whereNull('mobile_download_path');
            if (! $videoId) {
                $this->info('模式: 跳过已转码视频');
            }
        } else {
            $this->info('模式: 强制重新转码');
        }

        $count = $query->count();
        if ($count === 0) {
            $this->info('没有发现需要转码的视频。');

            return;
        }
        $this->info("待处理分P数量: {$count}");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunk(100, function ($parts) use ($bar, $mode) {
            foreach ($parts as $part) {
                // [修改] 将 mode 字符串传递给 Job
                dispatch(new TranscodeVideoJob($part, $mode));
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('任务已推送到队列。请通过 Horizon 查看实时处理进度。');
    }

    // 显示统计信息的方法
    /**
     * 按设备注入情况自动检测硬件加速模式：
     * NVIDIA（/dev/nvidia0）> Intel VAAPI（/dev/dri）> 纯 CPU。
     */
    protected function detectMode(): string
    {
        if (file_exists('/dev/nvidia0')) {
            return 'nvenc';
        }
        if (is_dir('/dev/dri')) {
            return 'qsv';
        }

        return 'cpu';
    }

    protected function showStatus()
    {
        $total = VideoPart::whereNotNull('video_download_path')->count();
        $done = VideoPart::whereNotNull('video_download_path')
            ->whereNotNull('mobile_download_path')
            ->count();

        $pending = $total - $done;
        $percent = $total > 0 ? round(($done / $total) * 100, 2) : 0;

        $this->table(
            ['统计项', '数值'],
            [
                ['已下载视频总数', $total],
                ['已完成转码', $done],
                ['待转码', $pending],
                ['总进度', "{$percent}%"],
            ]
        );

        $this->info("注意：此统计仅代表数据库状态。如果队列中有积压任务，'已完成' 数量会随队列处理逐渐增加。");
    }
}
