<?php

namespace App\Notifications;

use App\Models\PartsOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the parts desk: a dealer sent a parts order. Plain values, captured at send time. */
class PartsOrderSubmitted extends Notification
{
    private array $data;

    public function __construct(PartsOrder $order)
    {
        $this->data = [
            'number' => $order->label(),
            'dealer' => $order->dealerLink->dealer->name,
            'lines' => $order->lines->count(),
            'rush' => $order->ship_method === 'rush',
            'manufacturer' => $order->manufacturer->name,
            'url' => rtrim(config('app.frontend_url'), '/').'/m/'.$order->manufacturer->slug.'/parts-orders/'.$order->id,
        ];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->data;

        return (new MailMessage)
            ->subject(($d['rush'] ? 'RUSH ' : '')."Parts order {$d['number']} from {$d['dealer']}")
            ->line("{$d['dealer']} sent parts order {$d['number']} with {$d['lines']} line".($d['lines'] === 1 ? '' : 's').'.'.($d['rush'] ? ' They asked for rush shipping.' : ''))
            ->action('Open the order', $d['url'])
            ->salutation("DealerDock, for {$d['manufacturer']}");
    }
}
