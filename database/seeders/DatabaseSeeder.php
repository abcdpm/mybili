<?php

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. 基础功能开关
        $defaultSettings = [
            SettingKey::FAVORITE_SYNC_ENABLED->value            => 'on',
            SettingKey::VIDEO_DOWNLOAD_ENABLED->value           => 'on',
            SettingKey::DANMAKU_DOWNLOAD_ENABLED->value         => 'on',
            SettingKey::MULTI_PARTITION_DOWNLOAD_ENABLED->value => 'on',
            SettingKey::HUMAN_READABLE_NAME_ENABLED->value      => 'on',
            SettingKey::USAGE_ANALYTICS_ENABLED->value          => 'off',
        ];

        foreach ($defaultSettings as $key => $value) {
            // firstOrCreate 仅在初始化时生效，保护用户后续的修改
            Setting::firstOrCreate(
                ['name' => $key],
                ['value' => $value]
            );
        }

        // 2. 【新增】过滤器默认配置 (修复报错的关键)
        $filters = [
            SettingKey::NAME_EXCLUDE->value => [
                'type' => 'off',
                'contains' => '',
                'regex' => ''
            ],
            SettingKey::SIZE_EXCLUDE->value => [
                'type' => 'off',
                'custom_size' => 0
            ],
            SettingKey::DURATION_VIDEO_EXCLUDE->value => [
                'type' => 'off',
                'custom_duration' => 0
            ],
            SettingKey::DURATION_VIDEO_PART_EXCLUDE->value => [
                'type' => 'off',
                'custom_duration' => 0
            ],
            SettingKey::FAVORITE_EXCLUDE->value => [
                'enabled' => false,
                'selected' => []
            ],
        ];

        foreach ($filters as $key => $value) {
            Setting::firstOrCreate(['name' => $key], ['value' => $value]);
        }

        $this->command->info('Default settings and filters initialized successfully.');
    }
}