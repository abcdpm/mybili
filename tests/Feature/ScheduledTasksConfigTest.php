<?php

namespace Tests\Feature;

use App\Enums\SettingKey;
use App\Services\ScheduleConfigService;
use App\Services\SettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 定时任务配置化（第 14 项）测试。
 *
 * 覆盖：任务注册表完整性、默认 cron 与旧版硬编码一致、
 * 配置覆盖（频率/时刻）、运行时启用闸门、settings 校验。
 */
class ScheduledTasksConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_registers_all_registered_tasks(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $names = array_map(fn ($e) => $e->description, $schedule->events());

        // 注册表中的每个任务都必须有对应的调度事件
        foreach (array_keys(ScheduleConfigService::TASKS) as $key) {
            $this->assertContains($key, $names, "调度器缺少任务: {$key}");
        }
    }

    public function test_default_cron_matches_legacy_hardcoded_schedule(): void
    {
        $svc = app(ScheduleConfigService::class);

        // 与旧版硬编码调度逐一对照（兼容性断言）
        $this->assertSame('*/10 * * * *', $svc->cron('update_fav'));
        $this->assertSame('*/10 * * * *', $svc->cron('update_fav_videos'));
        $this->assertSame('0 4 * * *', $svc->cron('update_fav_videos_all'));
        $this->assertSame('0 4 * * *', $svc->cron('fix_invalid_fav_videos'));
        $this->assertSame('0 3 * * *', $svc->cron('comments_download'));
        $this->assertSame('0 5 * * *', $svc->cron('video_stats'));
        $this->assertSame('* * * * *', $svc->cron('download_queue'));
        $this->assertSame('0 6 * * *', $svc->cron('human_readable_names'));
        $this->assertSame('0 * * * *', $svc->cron('cover_scan'));
        $this->assertSame('0 * * * *', $svc->cron('no_parts_valid'));
        $this->assertSame('0 0 * * *', $svc->cron('freeze_fix'));
        $this->assertSame('0 6 * * *', $svc->cron('system_stats'));
        $this->assertSame('30 3 * * *', $svc->cron('rotate_system_logs'));
        $this->assertSame('40 3 * * *', $svc->cron('database_analyze'));
        $this->assertSame('45 5 * * 0', $svc->cron('database_vacuum'));
    }

    public function test_config_overrides_frequency_and_time(): void
    {
        app(SettingsService::class)->put(SettingKey::SCHEDULED_TASKS->value, [
            'comments_download' => ['enabled' => true, 'frequency' => 'hourly', 'time' => '12:30'],
            'download_queue' => ['enabled' => true, 'frequency' => 'everyFiveMinutes', 'time' => '00:00'],
        ]);

        $svc = app(ScheduleConfigService::class);
        $this->assertSame('0 * * * *', $svc->cron('comments_download'));
        $this->assertSame('*/5 * * * *', $svc->cron('download_queue'));

        // daily 且自定义时刻
        app(SettingsService::class)->put(SettingKey::SCHEDULED_TASKS->value, [
            'video_stats' => ['enabled' => true, 'frequency' => 'daily', 'time' => '21:15'],
        ]);
        app()->forgetInstance(SettingsService::class);
        app()->forgetInstance(ScheduleConfigService::class);
        $this->assertSame('15 21 * * *', app(ScheduleConfigService::class)->cron('video_stats'));
    }

    public function test_enabled_gate_and_invalid_values_fall_back_to_defaults(): void
    {
        app(SettingsService::class)->put(SettingKey::SCHEDULED_TASKS->value, [
            'stats_send' => ['enabled' => false, 'frequency' => 'bad-value', 'time' => '99:99'],
        ]);

        $svc = app(ScheduleConfigService::class);
        $this->assertFalse($svc->isEnabled('stats_send'));
        // 非法频率/时刻回落默认值（daily 00:00）
        $this->assertSame('0 0 * * *', $svc->cron('stats_send'));
        // 未配置的任务默认启用
        $this->assertTrue($svc->isEnabled('update_fav'));
    }

    public function test_settings_validation_accepts_new_config_shapes(): void
    {
        $this->app['config']->set('services.bilibili.setting_read_only', false);
        $this->app['config']->set('services.bilibili.setting_write_only', true);
        $this->app->forgetInstance(SettingsService::class);

        $payload = [
            'name_exclude' => ['contains' => '', 'regex' => '', 'type' => 'off'],
            'size_exclude' => ['custom_size' => 0, 'type' => 'off'],
            'duration_video_exclude' => ['custom_duration' => 0, 'type' => 'off'],
            'duration_video_part_exclude' => ['custom_duration' => 0, 'type' => 'off'],
            'fav_time_exclude' => ['custom_date' => '', 'type' => 'off'],
            'fav_exclude' => ['enabled' => false, 'selected' => []],
            'multi_partition_download_enabled' => 'on',
            'danmaku_download_enabled' => 'on',
            'video_download_enabled' => 'on',
            'favorite_sync_enabled' => 'on',
            'human_readable_name_enabled' => 'on',
            'download_comments_enabled' => 'on',
            'transcode_video_enabled' => 'off',
            'usage_analytics_enabled' => 'off',
            'telegram_bot_enabled' => 'off',
            'scheduled_tasks' => ['comments_download' => ['enabled' => false, 'frequency' => 'daily', 'time' => '03:00']],
            'comment_download_config' => ['auto_update' => true, 'percent' => 5, 'incremental' => 30, 'limit' => 0, 'max_videos' => 0, 'interval' => 0, 'sleep' => 0],
            'transcode_config' => ['hwaccel' => 'cpu', 'quality' => 24, 'preset' => 'fast'],
        ];

        $this->postJson('/api/settings', $payload)->assertStatus(200);

        $this->assertSame('0 3 * * *', app(ScheduleConfigService::class)->cron('comments_download'));
        $this->assertFalse(app(ScheduleConfigService::class)->isEnabled('comments_download'));
    }
}
