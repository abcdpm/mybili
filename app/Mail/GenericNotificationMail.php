<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 通用通知邮件（第 22 项：邮件推送）。
 *
 * 纯文本正文即可满足通知场景；from 可由 MailService 按配置动态指定，
 * 未指定时回落 Laravel 默认发件人（config mail.from）。
 */
class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    // 注意：Mailable 基类已定义 $subject 属性，这里用 mailSubject/mailBody 避免冲突
    public function __construct(
        public string $mailSubject,
        public string $mailBody,
        public ?string $fromAddress = null,
        public string $fromName = 'mybili',
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(subject: $this->mailSubject);
        if ($this->fromAddress) {
            $envelope->from = [new Address($this->fromAddress, $this->fromName)];
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(view: 'mail.generic-notification', with: [
            'subject' => $this->mailSubject,
            'body' => $this->mailBody,
        ]);
    }
}
