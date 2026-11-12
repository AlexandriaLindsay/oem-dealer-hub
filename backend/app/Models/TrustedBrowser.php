<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A browser someone said to remember for 30 days, so signing in there asks
 * for the password but not a code (App\Support\TrustedBrowsers). Global, like
 * the user. Only the token's hash is kept; the token lives in a cookie.
 */
class TrustedBrowser extends Model
{
    public const COOKIE = 'odh_trusted_browser';

    public const DAYS = 30;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Remembers this browser for $user: the row, and the cookie that carries the token. */
    public static function remember(User $user, ?string $userAgent): Cookie
    {
        $plain = Str::random(48);
        $browser = new self;
        $browser->forceFill([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'label' => self::describe($userAgent),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::DAYS),
        ])->save();

        // HttpOnly so page scripts can't read it; Secure outside local development;
        // Lax so following a link to OEM Dealer Hub still counts as this browser.
        return cookie(self::COOKIE, $plain, self::DAYS * 24 * 60, '/', null, (bool) config('session.secure'), true, false, 'lax');
    }

    /** The browser $user remembered, if the token in the cookie is one of theirs and still good. */
    public static function find(User $user, ?string $plain): ?self
    {
        if (! $plain) {
            return null;
        }

        return self::where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->first();
    }

    /** "Chrome on Windows" - enough to recognise it in a list, no more. */
    public static function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'A browser',
        };
        $system = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone or iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $system ? "{$browser} on {$system}" : $browser;
    }
}
