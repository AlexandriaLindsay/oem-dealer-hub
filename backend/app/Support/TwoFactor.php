<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app codes (TOTP). Email and text codes come later
 * (docs/PLAN.md section 4).
 */
final class TwoFactor
{
    public function __construct(private Google2FA $google2fa) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** What the QR code holds - the authenticator app reads it. */
    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('dealerdock.two_factor_issuer'), $user->email, $secret);
    }

    /**
     * Whether $code is right for $secret. Accepts the codes either side of now
     * (clock drift), and each code only once - a code seen signing in can't be
     * replayed.
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $key = "two-factor-used:{$user->id}";
        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, Cache::get($key), 1);

        if ($timestamp === false) {
            return false;
        }

        Cache::put($key, $timestamp === true ? $this->google2fa->getTimestamp() : $timestamp, now()->addMinutes(5));

        return true;
    }
}
