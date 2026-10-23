<?php

namespace App\Services;

use App\Enums\SettingKey;
use App\Jobs\SendMailNotificationJob;
use App\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 邮件推送服务（第 22 项：SMTP 配置 + 测试发送 + 失败重试）。
 *
 * 设计要点：
 *  - SMTP 配置存于 settings（mail_config），发送时运行时注入 mailer 配置，
 *    不改 config/mail.php，向后兼容且多配置来源互不干扰；
 *  - sendNow() 同步发送（测试发送用），queue() 投递 SendMailNotificationJob，
 *    队列 job 内置 3 次重试 + 指数退避（失败不静默，进 failed_jobs 可查）；
 *  - 未配置（host 为空）时跳过发送并记日志，避免误报错。
 */
class MailService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * 读取并规整 SMTP 配置。
     *
     * @return array{host: string, port: int, username: string, password: string, encryption: string, from_address: string, from_name: string, to: string}
     */
    public function config(): array
    {
        $cfg = (array) ($this->settings->get(SettingKey::MAIL_CONFIG) ?: []);

        return [
            'host' => (string) ($cfg['host'] ?? ''),
            'port' => (int) ($cfg['port'] ?? 465),
            'username' => (string) ($cfg['username'] ?? ''),
            'password' => (string) ($cfg['password'] ?? ''),
            'encryption' => (string) ($cfg['encryption'] ?? 'ssl'),
            'from_address' => (string) ($cfg['from_address'] ?? ''),
            'from_name' => (string) ($cfg['from_name'] ?? 'mybili'),
            'to' => (string) ($cfg['to'] ?? ''),
        ];
    }

    /**
     * 邮件通道是否已配置（host 与收件人均填写）。
     */
    public function configured(): bool
    {
        $cfg = $this->config();

        return $cfg['host'] !== '' && $cfg['to'] !== '';
    }

    /**
     * 同步发送（测试发送/手动触发）。
     *
     * @throws \Throwable 未配置或发送失败时抛出，由调用方展示给用户
     */
    public function sendNow(string $subject, string $body, ?string $to = null): void
    {
        $cfg = $this->config();
        if (! $this->configured()) {
            throw new \RuntimeException('邮件推送未配置：请填写 SMTP 服务器与收件人');
        }

        $this->applyRuntimeMailer($cfg);

        Mail::to($to ?: $cfg['to'])
            ->send(new GenericNotificationMail($subject, $body, $cfg['from_address'] ?: null, $cfg['from_name']));
    }

    /**
     * 入队发送（失败自动重试 3 次 + 退避），通知场景统一走此入口。
     */
    public function queue(string $subject, string $body, ?string $to = null): void
    {
        if (! $this->configured()) {
            Log::info('[邮件推送] 未配置 SMTP，跳过发送', ['subject' => $subject]);

            return;
        }

        SendMailNotificationJob::dispatch($subject, $body, $to);
    }

    /**
     * 运行时注入 SMTP mailer 配置（不落盘、不影响 config/mail.php 默认值）。
     * 加密映射：ssl → smtps 语义（Laravel 11+ 用 encryption 字段）；空值表示明文。
     */
    private function applyRuntimeMailer(array $cfg): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => $cfg['host'],
            'mail.mailers.smtp.port' => $cfg['port'],
            'mail.mailers.smtp.encryption' => $cfg['encryption'] !== '' ? $cfg['encryption'] : null,
            'mail.mailers.smtp.username' => $cfg['username'] !== '' ? $cfg['username'] : null,
            'mail.mailers.smtp.password' => $cfg['password'] !== '' ? $cfg['password'] : null,
        ]);
    }
}
