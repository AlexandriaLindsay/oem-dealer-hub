<?php

namespace App\Notifications;

use App\Models\Manufacturer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a dealer's people: please confirm the machines on your lot. The button
 * opens a page where one click confirms everything - no sign-in needed, and
 * nothing changes just by opening the link (mail scanners open links).
 *
 * Sent straight away, never queued: the link carries the check's token, and
 * a queued mail would leave that token sitting in the jobs table (and
 * failed_jobs, and backups) where anyone reading them could answer the check.
 */
class StockCheckRequested extends Notification
{
    private array $data;

    public function __construct(Manufacturer $manufacturer, string $dealer, int $count, string $dueOn, string $token)
    {
        $base = rtrim(config('app.frontend_url'), '/');
        $this->data = [
            'manufacturer' => $manufacturer->name,
            'dealer' => $dealer,
            'count' => $count,
            'due' => now()->parse($dueOn)->format('F j'),
            'url' => $base.'/stock-check/'.$token,
            'app_url' => $base.'/m/'.$manufacturer->slug.'/stock-checks',
        ];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->data;
        $machines = $d['count'] === 1 ? '1 machine' : "{$d['count']} machines";

        return (new MailMessage)
            ->subject("Quick stock check: {$machines} from {$d['manufacturer']}")
            ->line("{$d['manufacturer']} shows {$machines} at {$d['dealer']}. Are they all still on your lot?")
            ->action('Check the list', $d['url'])
            ->line('If they\'re all there, it\'s one click. If one isn\'t, mark it "Not here" with a note - sold, moved, or on loan.')
            ->line("Please answer by {$d['due']}. You can also do it in OEM Dealer Hub: {$d['app_url']}")
            ->salutation("OEM Dealer Hub, for {$d['manufacturer']}");
    }
}
