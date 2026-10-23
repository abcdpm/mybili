<?php

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 邮件通知发送任务（第 22 项：失败重试）。
 *
 * 重试策略：最多 3 次尝试 + 指数退避（10s/60s），保证 SMTP 短暂故障可自愈；
 * 最终失败进入 failed_jobs（可见可查），不静默吞错。
 * 发送配置在 handle() 内重新读取并注入，保证任务执行时用最新配置。
 */
class SendMailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 最大尝试次数 */
    public int $tries = 3;

    /** 重试退避秒数 */
    public array $backoff = [10, 60];

    public function __construct(
        public string $subject,
        public string $body,
        public ?string $to = null,
    ) {
        $this->onQueue('mail');
    }

    public function handle(MailService $mailService): void
    {
        $mailService->sendNow($this->subject, $this->body, $this->to);
    }

    /**
     * 全部重试耗尽后记录失败原因（进 failed_jobs）。
     */
    public function failed(\Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::error('[邮件推送] 发送最终失败（已耗尽重试）', [
            'subject' => $this->subject,
            'error' => $e->getMessage(),
        ]);
    }
}
