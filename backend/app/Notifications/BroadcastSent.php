<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A broadcast, by email, to one dealer's person. Queued - sending to hundreds
 * of dealers shouldn't hold up the web request - and built only from plain
 * values, so the queued job never looks anything up without a tenant.
 */
class BroadcastSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $manufacturer,
        private readonly string $title,
        private readonly string $body,
        private readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("{$this->manufacturer}: {$this->title}");
        foreach (preg_split('/\R{2,}/', trim($this->body)) as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail->action('Open in DealerDock', $this->url)->salutation("Sent by {$this->manufacturer} through DealerDock");
    }
}
