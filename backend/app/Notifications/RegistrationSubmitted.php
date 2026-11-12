<?php

namespace App\Notifications;

use App\Models\Registration;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the manufacturer's reviewers: a dealer registered a machine. Built from
 * plain values at send time, so it never has to look anything up later
 * without a tenant set.
 */
class RegistrationSubmitted extends Notification
{
    private array $data;

    public function __construct(Registration $registration)
    {
        $machine = $registration->machine;
        $this->data = [
            'type' => $registration->type === 'sale' ? 'sale' : 'rental',
            'serial' => $machine->serial_no,
            'model' => $machine->productModel->name,
            'dealer' => $registration->dealerLink->dealer->name,
            'manufacturer' => $registration->manufacturer->name,
            'url' => rtrim(config('app.frontend_url'), '/').'/m/'.$registration->manufacturer->slug.'/registrations/'.$registration->id,
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
            ->subject("New {$d['type']} registration: {$d['model']} {$d['serial']}")
            ->line("{$d['dealer']} registered {$d['model']} serial {$d['serial']} as a {$d['type']}.")
            ->line('It\'s waiting for your review in OEM Dealer Hub.')
            ->action('Review the registration', $d['url'])
            ->salutation("OEM Dealer Hub, for {$d['manufacturer']}");
    }
}
