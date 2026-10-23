<?php

namespace Tests\Feature;

use App\Enums\SettingKey;
use App\Jobs\SendMailNotificationJob;
use App\Mail\GenericNotificationMail;
use App\Services\MailService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 邮件推送（第 22 项）测试。
 *
 * 覆盖：未配置跳过/同步发送、队列投递与失败重试策略、测试发送端点。
 */
class MailPushTest extends TestCase
{
    use RefreshDatabase;

    private function configureMail(): void
    {
        app(SettingsService::class)->put(SettingKey::MAIL_CONFIG->value, [
            'host' => 'smtp.example.com', 'port' => 465, 'username' => 'u', 'password' => 'p',
            'encryption' => 'ssl', 'from_address' => 'noreply@example.com', 'from_name' => 'mybili',
            'to' => 'me@example.com',
        ]);
    }

    public function test_unconfigured_mail_queue_is_skipped(): void
    {
        Queue::fake();
        app(MailService::class)->queue('主题', '内容');
        Queue::assertNothingPushed();
    }

    public function test_configured_mail_queue_dispatches_retryable_job(): void
    {
        $this->configureMail();
        Queue::fake();

        app(MailService::class)->queue('主题', '内容');

        Queue::assertPushed(SendMailNotificationJob::class, function ($job) {
            return $job->subject === '主题'
                && $job->tries === 3
                && $job->backoff === [10, 60];
        });
    }

    public function test_send_now_requires_configuration(): void
    {
        $this->expectException(\RuntimeException::class);
        app(MailService::class)->sendNow('主题', '内容');
    }

    public function test_send_now_sends_mailable_to_recipient(): void
    {
        $this->configureMail();
        Mail::fake();

        app(MailService::class)->sendNow('主题', '内容');

        Mail::assertSent(GenericNotificationMail::class, function ($mail) {
            return $mail->mailSubject === '主题' && $mail->hasTo('me@example.com');
        });
    }

    public function test_job_handle_delegates_to_mail_service(): void
    {
        $this->configureMail();
        Mail::fake();

        (new SendMailNotificationJob('任务主题', '任务内容'))->handle(app(MailService::class));

        Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->mailSubject === '任务主题');
    }

    public function test_test_mail_endpoint_reports_unconfigured(): void
    {
        $this->postJson('/api/settings/test-mail')
            ->assertStatus(200)
            ->assertJsonPath('code', 1);
    }

    public function test_settings_validation_accepts_mail_config(): void
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
            'mail_config' => [
                'host' => 'smtp.example.com', 'port' => 465, 'username' => 'u', 'password' => 'p',
                'encryption' => 'ssl', 'from_address' => 'noreply@example.com', 'from_name' => 'mybili',
                'to' => 'me@example.com',
            ],
        ];

        $this->postJson('/api/settings', $payload)->assertStatus(200);
        $this->assertTrue(app(MailService::class)->configured());
    }
}
