<?php

declare(strict_types=1);

namespace EasyLog\Mail;

use EasyLog\Models\AlertEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 告警事件邮件（纯文本）
 */
class AlertEventMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public AlertEvent $event)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[EasyLog][%s] %s',
                strtoupper($this->event->severity),
                $this->event->rule?->name ?? '告警通知'
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.alert-event',
            with: ['event' => $this->event],
        );
    }
}
