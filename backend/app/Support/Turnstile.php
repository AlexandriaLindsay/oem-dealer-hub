<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Cloudflare Turnstile - the "are you a person" check on sign-up, sign-in and
 * password reset (docs/PLAN.md section 4). The browser gets a token from
 * Cloudflare's widget; we ask Cloudflare whether it's genuine.
 *
 * - Not configured (no secret): skipped. Local development only - production
 *   refuses to start without it (AppServiceProvider).
 * - A missing, used or forged token: refused.
 * - Cloudflare unreachable: let through and logged. Turning everyone away
 *   during a Cloudflare outage would be worse; the per-IP rate limit on these
 *   routes still applies.
 */
final class Turnstile
{
    public const FIELD = 'turnstile_token';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return (bool) config('services.turnstile.secret');
    }

    /** @throws ValidationException when the token isn't genuine */
    public static function check(Request $request): void
    {
        if (! self::enabled()) {
            return;
        }

        $token = (string) $request->input(self::FIELD, '');
        if ($token === '') {
            throw ValidationException::withMessages([self::FIELD => 'Please complete the "verify you are human" check.']);
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if (! $response->successful()) {
            logger()->warning('Turnstile verification unavailable', ['status' => $response->status()]);

            return;
        }

        if (! $response->json('success')) {
            throw ValidationException::withMessages([self::FIELD => 'We couldn\'t confirm you\'re a person. Please try the check again.']);
        }
    }
}
