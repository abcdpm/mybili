<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Services\SettingsService;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(public SettingsService $settings, public TelegramBotService $telegramBot) {}

    public function getSettings()
    {
        $presets = [
            SettingKey::DANMAKU_DOWNLOAD_ENABLED->value => 'on',
            SettingKey::VIDEO_DOWNLOAD_ENABLED->value => 'on',
            SettingKey::FAVORITE_SYNC_ENABLED->value => 'on',
            SettingKey::HUMAN_READABLE_NAME_ENABLED->value => 'on',
            SettingKey::USAGE_ANALYTICS_ENABLED->value => 'off',
            SettingKey::TRANSCODE_VIDEO_ENABLED->value => 'off',
            SettingKey::DOWNLOAD_COMMENTS_ENABLED->value => 'on',
            SettingKey::FAVORITE_EXCLUDE->value => [
                'enabled' => false,
                'selected' => [],
            ],
            SettingKey::TELEGRAM_BOT_API_URL->value => '',
            SettingKey::TELEGRAM_BOT_ENABLED->value => 'off',
            SettingKey::TELEGRAM_BOT_TOKEN->value => '',
            SettingKey::TELEGRAM_CHAT_ID->value => '',
            SettingKey::MULTI_PARTITION_DOWNLOAD_ENABLED->value => 'on',
            SettingKey::NAME_EXCLUDE->value => [
                'contains' => '',
                'regex' => '',
                'type' => 'off',
            ],
            SettingKey::SIZE_EXCLUDE->value => [
                'custom_size' => 0,
                'type' => 'off',
            ],
            SettingKey::DURATION_VIDEO_EXCLUDE->value => [
                'custom_duration' => 0,
                'type' => 'off',
            ],
            SettingKey::DURATION_VIDEO_PART_EXCLUDE->value => [
                'custom_duration' => 0,
                'type' => 'off',
            ],
            SettingKey::FAV_TIME_EXCLUDE->value => [
                'custom_date' => '',
                'type' => 'off',
            ],
            SettingKey::TELEGRAM_BOT_API_URL->value => '',
            SettingKey::TELEGRAM_BOT_ENABLED->value => 'off',
            SettingKey::TELEGRAM_BOT_TOKEN->value => '',
            SettingKey::TELEGRAM_CHAT_ID->value => '',
            // 定时任务配置：空数组表示全部按注册表默认运行（见 ScheduleConfigService）
            SettingKey::SCHEDULED_TASKS->value => [],
            // 评论下载配置：默认值与旧版命令行参数一致（auto-update 3% + 增量 20 条）
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value => [
                'auto_update' => true,
                'percent' => 3,
                'incremental' => 20,
                'limit' => 0,
                'max_videos' => 0,
                'interval' => 0,
                'sleep' => 0,
            ],
            // 转码配置：默认值与 TranscodeVideoJob 旧硬编码一致
            SettingKey::TRANSCODE_CONFIG->value => [
                'hwaccel' => 'auto',
                'quality' => 26,
                'preset' => 'veryfast',
            ],
            // 邮件推送（SMTP）：默认关闭（host 为空即未配置）
            SettingKey::MAIL_CONFIG->value => [
                'host' => '',
                'port' => 465,
                'username' => '',
                'password' => '',
                'encryption' => 'ssl',
                'from_address' => '',
                'from_name' => 'mybili',
                'to' => '',
            ],
        ];

        foreach ($presets as $key => $value) {
            $got = $this->settings->get($key);
            if ($got) {
                $presets[$key] = $got;
            }
        }

        return response()->json($presets);
    }

    /**
     * 定时任务注册表元数据（第 14 项：定时任务配置页）。
     *
     * 返回每个任务的任务键、中文名称、说明、默认频率与当前生效配置；
     * 保存走 POST /settings 的 scheduled_tasks 字段，此处只读。
     */
    public function scheduledTasks()
    {
        return response()->json([
            'frequencies' => array_keys(\App\Services\ScheduleConfigService::FREQUENCIES),
            'tasks' => app(\App\Services\ScheduleConfigService::class)->describe(),
        ]);
    }

    /**
     * 邮件推送测试发送（第 22 项）。
     *
     * 使用当前已保存的 SMTP 配置发送一封测试邮件，成功/失败原因直接反馈到界面；
     * 发送走同步通道（MailService::sendNow），不入队以便用户立即看到结果。
     */
    public function testMailConnection()
    {
        try {
            app(\App\Services\MailService::class)->sendNow(
                'mybili 邮件推送测试',
                '这是一封测试邮件。收到此邮件表示 SMTP 配置正确，邮件推送功能已就绪。'.PHP_EOL.'发送时间：'.now()->toDateTimeString()
            );

            return response()->json(['code' => 0, 'message' => '测试邮件已发送，请检查收件箱']);
        } catch (\Throwable $e) {
            return response()->json(['code' => 1, 'message' => '发送失败：'.$e->getMessage()], 200);
        }
    }

    public function saveSettings(Request $request)
    {
        if (config('services.bilibili.setting_read_only')) {
            abort(403);
        }
        $data = $request->validate([
            SettingKey::MULTI_PARTITION_DOWNLOAD_ENABLED->value => 'required|string|in:on,off',
            SettingKey::DANMAKU_DOWNLOAD_ENABLED->value => 'required|string|in:on,off',
            SettingKey::VIDEO_DOWNLOAD_ENABLED->value => 'required|string|in:on,off',
            SettingKey::FAVORITE_SYNC_ENABLED->value => 'required|string|in:on,off',
            SettingKey::HUMAN_READABLE_NAME_ENABLED->value => 'required|string|in:on,off',
            SettingKey::USAGE_ANALYTICS_ENABLED->value => 'required|string|in:on,off',
            SettingKey::TRANSCODE_VIDEO_ENABLED->value => 'required|string|in:on,off',
            SettingKey::DOWNLOAD_COMMENTS_ENABLED->value => 'required|string|in:on,off',

            SettingKey::NAME_EXCLUDE->value => 'required|array',
            SettingKey::NAME_EXCLUDE->value.'.contains' => 'required_if:name_exclude.type,contains|nullable|string',
            SettingKey::NAME_EXCLUDE->value.'.regex' => 'required_if:name_exclude.type,regex|nullable|string',
            SettingKey::NAME_EXCLUDE->value.'.type' => 'required|string|in:off,contains,regex',

            SettingKey::SIZE_EXCLUDE->value => 'required|array',
            SettingKey::SIZE_EXCLUDE->value.'.custom_size' => 'required_if:size_exclude.type,custom|integer',
            SettingKey::SIZE_EXCLUDE->value.'.type' => 'required|string|in:off,1GB,2GB,custom',

            SettingKey::DURATION_VIDEO_EXCLUDE->value => 'required|array',
            SettingKey::DURATION_VIDEO_EXCLUDE->value.'.custom_duration' => 'required_if:duration_video_exclude.type,custom|integer',
            SettingKey::DURATION_VIDEO_EXCLUDE->value.'.type' => 'required|string|in:off,30min,60min,120min,custom',

            SettingKey::DURATION_VIDEO_PART_EXCLUDE->value => 'required|array',
            SettingKey::DURATION_VIDEO_PART_EXCLUDE->value.'.custom_duration' => 'required_if:duration_video_part_exclude.type,custom|integer',
            SettingKey::DURATION_VIDEO_PART_EXCLUDE->value.'.type' => 'required|string|in:off,30min,60min,120min,custom',

            SettingKey::FAV_TIME_EXCLUDE->value => 'required|array',
            SettingKey::FAV_TIME_EXCLUDE->value.'.custom_date' => 'required_if:fav_time_exclude.type,custom|nullable|date_format:Y-m-d',
            SettingKey::FAV_TIME_EXCLUDE->value.'.type' => 'required|string|in:off,1m,3m,6m,custom',

            SettingKey::FAVORITE_EXCLUDE->value => 'required|array',
            SettingKey::FAVORITE_EXCLUDE->value.'.enabled' => 'required|boolean',
            SettingKey::FAVORITE_EXCLUDE->value.'.selected' => 'required_if:fav_exclude.enabled,true|array',

            SettingKey::TELEGRAM_BOT_API_URL->value => 'nullable|string',
            SettingKey::TELEGRAM_BOT_ENABLED->value => 'required|string|in:on,off',
            SettingKey::TELEGRAM_BOT_TOKEN->value => 'required_if:telegram_bot_enabled,on|string',
            SettingKey::TELEGRAM_CHAT_ID->value => 'required_if:telegram_bot_enabled,on|string',

            // 定时任务配置（JSON）：key => {enabled: bool, frequency: enum, time: "HH:MM"}
            SettingKey::SCHEDULED_TASKS->value => 'nullable|array',
            SettingKey::SCHEDULED_TASKS->value.'.*.enabled' => 'nullable|boolean',
            SettingKey::SCHEDULED_TASKS->value.'.*.frequency' => 'nullable|string|in:everyMinute,everyFiveMinutes,everyTenMinutes,everyThirtyMinutes,hourly,daily,weekly',
            SettingKey::SCHEDULED_TASKS->value.'.*.time' => 'nullable|date_format:H:i',

            // 评论下载配置（JSON）
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value => 'nullable|array',
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value.'.auto_update' => 'nullable|boolean',
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value.'.percent' => 'nullable|integer|min:1|max:100',
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value.'.incremental' => 'nullable|integer|min:0|max:1000',
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value.'.limit' => 'nullable|integer|min:0|max:20000',
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value.'.max_videos' => 'nullable|integer|min:0|max:5000',
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value.'.interval' => 'nullable|integer|min:0|max:3600',
            SettingKey::COMMENT_DOWNLOAD_CONFIG->value.'.sleep' => 'nullable|integer|min:0|max:60',

            // 转码配置（JSON）
            SettingKey::TRANSCODE_CONFIG->value => 'nullable|array',
            SettingKey::TRANSCODE_CONFIG->value.'.hwaccel' => 'nullable|string|in:auto,cpu,cuda,qsv',
            SettingKey::TRANSCODE_CONFIG->value.'.quality' => 'nullable|integer|min:0|max:51',
            SettingKey::TRANSCODE_CONFIG->value.'.preset' => 'nullable|string|in:ultrafast,veryfast,faster,fast,medium,slow,p1,p2,p3,p4,p5,p6,p7',

            // 邮件推送配置（JSON，SMTP）
            SettingKey::MAIL_CONFIG->value => 'nullable|array',
            SettingKey::MAIL_CONFIG->value.'.host' => 'nullable|string|max:255',
            SettingKey::MAIL_CONFIG->value.'.port' => 'nullable|integer|min:1|max:65535',
            SettingKey::MAIL_CONFIG->value.'.username' => 'nullable|string|max:255',
            SettingKey::MAIL_CONFIG->value.'.password' => 'nullable|string|max:255',
            SettingKey::MAIL_CONFIG->value.'.encryption' => 'nullable|string|in:,ssl,tls,starttls',
            SettingKey::MAIL_CONFIG->value.'.from_address' => 'nullable|email|max:255',
            SettingKey::MAIL_CONFIG->value.'.from_name' => 'nullable|string|max:100',
            SettingKey::MAIL_CONFIG->value.'.to' => 'nullable|string|max:500',
        ]);

        foreach ($data as $key => $value) {
            $this->settings->put($key, $value);
        }

        return response()->json(['message' => 'Settings saved successfully']);
    }

    public function testTelegramConnection(Request $request)
    {
        $data = $request->validate([
            SettingKey::TELEGRAM_BOT_TOKEN->value => 'required|string',
            SettingKey::TELEGRAM_CHAT_ID->value => 'required|string',
            SettingKey::TELEGRAM_BOT_API_URL->value => 'nullable|string',
        ]);

        $result = $this->telegramBot->testConnection([
            'bot_token' => $data[SettingKey::TELEGRAM_BOT_TOKEN->value],
            'chat_id' => $data[SettingKey::TELEGRAM_CHAT_ID->value],
            'bot_url' => $data[SettingKey::TELEGRAM_BOT_API_URL->value] ?? null,
        ]);

        return response()->json(['message' => 'Telegram connection test successful', 'result' => $result]);
    }
}
