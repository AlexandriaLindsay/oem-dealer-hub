<?php

namespace App\Notifications;

use App\Models\Registration;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To whoever submitted a registration: it was approved, or rejected and why. */
class RegistrationReviewed extends Notification
{
    private array $data;

    public function __construct(Registration $registration)
    {
        $machine = $registration->machine;
        $this->data = [
            'approved' => $registration->status === Registration::APPROVED,
            'type' => $registration->type,
            'serial' => $machine->serial_no,
            'model' => $machine->productModel->name,
            'reason' => $registration->rejection_reason,
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
        $what = "{$d['model']} serial {$d['serial']}";

        if ($d['approved']) {
            return (new MailMessage)
                ->subject("Registration approved: {$d['model']} {$d['serial']}")
                ->line("{$d['manufacturer']} approved your {$d['type']} registration for {$what}.")
                ->action('View the registration', $d['url'])
                ->salutation("DealerDock, for {$d['manufacturer']}");
        }

        return (new MailMessage)
            ->subject("Registration needs changes: {$d['model']} {$d['serial']}")
            ->line("{$d['manufacturer']} couldn't approve your {$d['type']} registration for {$what}.")
            ->line('Their reason: '.$d['reason'])
            ->line('You can fix what they asked for and register the machine again.')
            ->action('View the registration', $d['url'])
            ->salutation("DealerDock, for {$d['manufacturer']}");
    }
}
