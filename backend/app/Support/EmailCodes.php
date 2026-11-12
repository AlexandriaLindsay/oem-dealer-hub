<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\TwoFactorCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two-factor codes by email: for people who'd rather not use an
 * authenticator app, and for anyone whose phone isn't to hand.
 *
 * A code is 6 digits, good for 10 minutes and for one use, and only its hash
 * is kept (in the cache). Five wrong tries and it's spent - ask for a new
 * one. Sending is limited too (once a minute, 6 an hour), so the email
 * address can't be flooded.
 */
final class EmailCodes
{
    private const MINUTES = 10;

    private const TRIES = 5;

    /**
     * Emails a new code. False when they've asked too often. $atSignIn: the
     * code sent automatically after a right password - it skips the
     * once-a-minute limit (that's for "send me another"), so signing in
     * again soon after doesn't leave them waiting for an email that never
     * comes; the hourly cap still applies.
     */
    public function send(User $user, bool $atSignIn = false): bool
    {
        $minute = "email-code-send-minute:{$user->id}";
        $hour = "email-code-send-hour:{$user->id}";
        if ((! $atSignIn && RateLimiter::tooManyAttempts($minute, 1)) || RateLimiter::tooManyAttempts($hour, 6)) {
            return false;
        }
        RateLimiter::hit($minute, 60);
        RateLimiter::hit($hour, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->key($user), ['hash' => hash('sha256', $code), 'tries' => 0], now()->addMinutes(self::MINUTES));
        $user->notify(new TwoFactorCode($code, self::MINUTES));

        return true;
    }

    /** Whether $code is the one just emailed. A right code is used up; so are five wrong ones. */
    public function verify(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        $saved = Cache::get($this->key($user));
        if (! $saved || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        if (hash_equals($saved['hash'], hash('sha256', $code))) {
            Cache::forget($this->key($user));

            return true;
        }

        $saved['tries']++;
        $saved['tries'] >= self::TRIES
            ? Cache::forget($this->key($user))
            : Cache::put($this->key($user), $saved, now()->addMinutes(self::MINUTES));

        return false;
    }

    /** "a•••@example.com" - enough to know which inbox to check. */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }

    private function key(User $user): string
    {
        return "email-code:{$user->id}";
    }
}
