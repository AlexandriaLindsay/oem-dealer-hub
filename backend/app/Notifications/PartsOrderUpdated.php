<?php

namespace App\Notifications;

use App\Models\PartsOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the dealer who ordered: the parts desk started, shipped or cancelled it. */
class PartsOrderUpdated extends Notification
{
    private array $data;

    public function __construct(PartsOrder $order)
    {
        $this->data = [
            'number' => $order->label(),
            'po' => $order->po_number,
            'status' => $order->status,
            'carrier' => $order->carrier,
            'tracking' => $order->tracking_number,
            'note' => $order->parts_note,
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
        $ref = $d['number'].($d['po'] ? " (your PO {$d['po']})" : '');
        $mail = (new MailMessage)->salutation("OEM Dealer Hub, for {$d['manufacturer']}");

        $mail = match ($d['status']) {
            PartsOrder::IN_PROGRESS => $mail->subject("Parts order {$d['number']} is being prepared")
                ->line("{$d['manufacturer']}'s parts desk is working on order {$ref}."),
            PartsOrder::SHIPPED => $mail->subject("Parts order {$d['number']} has shipped")
                ->line("Order {$ref} has shipped".($d['carrier'] ? " with {$d['carrier']}" : '').'.')
                ->line($d['tracking'] ? "Tracking number: {$d['tracking']}" : ''),
            default => $mail->subject("Parts order {$d['number']} was cancelled")
                ->line("{$d['manufacturer']}'s parts desk cancelled order {$ref}."),
        };

        if ($d['note']) {
            $mail->line('Note from the parts desk: '.$d['note']);
        }

        return $mail->action('Open the order', $d['url']);
    }
}
