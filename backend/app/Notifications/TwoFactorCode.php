<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A sign-in code by email (App\Support\EmailCodes). Sent straight away, not
 * queued - someone is waiting at the sign-in screen for it.
 */
class TwoFactorCode extends Notification
{
    public function __construct(private string $code, private int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your OEM Dealer Hub code: {$this->code}")
            ->line('Enter this code to finish signing in to OEM Dealer Hub:')
            ->line("**{$this->code}**")
            ->line("It works once, for the next {$this->minutes} minutes.")
            ->line("If you didn't just try to sign in, someone may know your password. Change it, and don't share this code with anyone.")
            ->salutation('OEM Dealer Hub');
    }
}
