<?php

namespace App\Services;

use App\Enums\SettingKey;
use Illuminate\Console\Scheduling\Event;

/**
 * 定时任务配置服务（第 14 项：可配置化定时任务）。
 *
 * 设计要点：
 *  - 任务注册表集中定义（任务键、中文名、说明、默认频率、默认执行时刻），前端配置页与调度器共用一份元数据；
 *  - enabled / frequency / time 三项运行时读取 settings（scheduled_tasks 键，JSON），
 *    调度器每分钟 cron 拉起时重建 schedule，因此修改配置约 1 分钟内生效，无需重启容器；
 *  - 兼容性：无配置时全部回落注册表默认值，与旧版硬编码调度行为完全一致；
 *  - 运行时双重闸门：enabled=false 时任务注册的闭包直接跳过（即使 cron 命中也零开销返回）。
 */
class ScheduleConfigService
{
    /**
     * 频率枚举 → cron 表达式（daily/weekly 的时分由 time 参数补充）。
     */
    public const FREQUENCIES = [
        'everyMinute' => '* * * * *',
        'everyFiveMinutes' => '*/5 * * * *',
        'everyTenMinutes' => '*/10 * * * *',
        'everyThirtyMinutes' => '*/30 * * * *',
        'hourly' => '0 * * * *',
        'daily' => '{time} * * *',
        'weekly' => '{time} * * 0',
    ];

    /**
     * 任务注册表。
     *
     * @var array<string, array{name: string, description: string, default_frequency: string, default_time: string}>
     */
    public const TASKS = [
        'update_fav' => [
            'name' => '收藏夹同步', 'description' => '定期拉取收藏夹列表元数据（标题、媒体数量等），不涉及视频内容',
            'default_frequency' => 'everyTenMinutes', 'default_time' => '',
        ],
        'update_fav_videos' => [
            'name' => '收藏视频增量更新', 'description' => '快速检测收藏夹第一页的视频变更（加入/删除），凌晨 4 点自动让位全量扫描',
            'default_frequency' => 'everyTenMinutes', 'default_time' => '',
        ],
        'update_fav_videos_all' => [
            'name' => '收藏视频全量同步', 'description' => '全分页深度扫描收藏夹，发现被删除或漏掉的视频，保持与 B 站一致',
            'default_frequency' => 'daily', 'default_time' => '04:00',
        ],
        'fix_invalid_fav_videos' => [
            'name' => '失效视频修复', 'description' => '复查本地标记为失效的视频是否恢复正常',
            'default_frequency' => 'daily', 'default_time' => '04:00',
        ],
        'comments_download' => [
            'name' => '评论批量下载', 'description' => '按"评论下载设置"批量下载/增量刷新视频评论，任务错峰投递避免堆积',
            'default_frequency' => 'daily', 'default_time' => '03:00',
        ],
        'video_stats' => [
            'name' => '播放量更新', 'description' => '每天抽取最久未更新的视频平滑刷新播放量/弹幕数（防风控节流）',
            'default_frequency' => 'daily', 'default_time' => '05:00',
        ],
        'update_subscription' => [
            'name' => '订阅更新', 'description' => '检查已订阅 UP 主/系列是否有新发布视频',
            'default_frequency' => 'everyTenMinutes', 'default_time' => '',
        ],
        'download_queue' => [
            'name' => '下载队列处理', 'description' => '持续处理视频/音频下载队列（关闭后新任务将滞留在队列中）',
            'default_frequency' => 'everyMinute', 'default_time' => '',
        ],
        'human_readable_names' => [
            'name' => '可读文件名', 'description' => '将下载的视频通过硬链接整理为 Emby/Plex 友好的命名格式',
            'default_frequency' => 'daily', 'default_time' => '06:00',
        ],
        'cover_scan' => [
            'name' => '封面扫描', 'description' => '下载收藏夹及视频缺失的封面图片',
            'default_frequency' => 'hourly', 'default_time' => '',
        ],
        'no_parts_valid' => [
            'name' => '无效视频清理', 'description' => '处理缺失分P信息的有效视频，补全元数据',
            'default_frequency' => 'hourly', 'default_time' => '',
        ],
        'freeze_fix' => [
            'name' => '冻结状态修复', 'description' => '修复视频冻结（仅台湾/港澳等）状态标记',
            'default_frequency' => 'daily', 'default_time' => '00:00',
        ],
        'system_stats' => [
            'name' => '系统统计', 'description' => '全量统计数据库行数与存储占用（关于页数据来源）',
            'default_frequency' => 'daily', 'default_time' => '06:00',
        ],
        'rotate_system_logs' => [
            'name' => '日志瘦身', 'description' => '防止 laravel.log 无限增长撑爆磁盘（超 50MB 时保留最新 5000 行）',
            'default_frequency' => 'daily', 'default_time' => '03:30',
        ],
        'database_analyze' => [
            'name' => '数据库统计更新', 'description' => 'PRAGMA optimize 更新查询计划统计信息（轻量，秒级）',
            'default_frequency' => 'daily', 'default_time' => '03:40',
        ],
        'database_vacuum' => [
            'name' => '数据库空间回收', 'description' => 'WAL checkpoint + VACUUM 回收空闲页（大库需几分钟，期间写入短暂阻塞）',
            'default_frequency' => 'weekly', 'default_time' => '05:45',
        ],
        'horizon_snapshot' => [
            'name' => '队列监控快照', 'description' => '记录 Horizon 队列负载指标，生成仪表盘图表',
            'default_frequency' => 'everyMinute', 'default_time' => '',
        ],
        'stats_send' => [
            'name' => '使用统计上报', 'description' => '发送非敏感的匿名使用统计数据（用于项目改进分析）',
            'default_frequency' => 'daily', 'default_time' => '00:00',
        ],
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * 取单个任务的生效配置（缺失项回落注册表默认值）。
     *
     * @return array{enabled: bool, frequency: string, time: string}
     */
    public function task(string $key): array
    {
        $defaults = self::TASKS[$key] ?? ['default_frequency' => 'daily', 'default_time' => '00:00'];

        // 容错读取：routes/console.php 在应用引导期即构建调度（早于测试迁移/数据库就绪），
        // 读不到 settings 时回落注册表默认值 —— 生产环境数据库就绪，行为与配置一致。
        try {
            $all = (array) ($this->settings->get(SettingKey::SCHEDULED_TASKS) ?: []);
        } catch (\Throwable) {
            $all = [];
        }
        $cfg = (array) ($all[$key] ?? []);

        return [
            'enabled' => array_key_exists('enabled', $cfg) ? (bool) $cfg['enabled'] : true,
            'frequency' => in_array($cfg['frequency'] ?? '', array_keys(self::FREQUENCIES), true)
                ? $cfg['frequency'] : $defaults['default_frequency'],
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $cfg['time'] ?? '') ? $cfg['time'] : ($defaults['default_time'] ?: '00:00'),
        ];
    }

    /**
     * 任务是否启用（调度器运行时闸门）。
     */
    public function isEnabled(string $key): bool
    {
        return $this->task($key)['enabled'];
    }

    /**
     * 计算任务的 cron 表达式（daily/weekly 拼接配置的执行时刻）。
     */
    public function cron(string $key): string
    {
        $cfg = $this->task($key);
        $expr = self::FREQUENCIES[$cfg['frequency']] ?? self::FREQUENCIES['daily'];

        return str_replace('{time}', $this->timeToCron($cfg['time']), $expr);
    }

    /**
     * 把任务配置应用到调度事件（名称 + 动态 cron）。
     */
    public function apply(Event $event, string $key): Event
    {
        $event->name($key);
        $event->cron($this->cron($key));

        return $event;
    }

    /**
     * 配置页元数据：注册表 + 当前配置（供 API 输出）。
     */
    public function describe(): array
    {
        $tasks = [];
        foreach (self::TASKS as $key => $meta) {
            $tasks[] = [
                'key' => $key,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'default' => [
                    'frequency' => $meta['default_frequency'],
                    'time' => $meta['default_time'],
                ],
                'current' => $this->task($key),
            ];
        }

        return $tasks;
    }

    /**
     * "HH:MM" → cron 的 "分 时"。
     */
    private function timeToCron(string $time): string
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ((int) $minute).' '.((int) $hour);
    }
}
