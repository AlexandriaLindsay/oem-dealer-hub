<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrustedBrowser;
use App\Support\EmailCodes;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Setting up two-factor sign-in, one of two ways:
 *
 * - an authenticator app (recommended): setup() makes a secret and shows it
 *   as a QR code; confirm() switches it on once the app gives a right code.
 * - email codes: emailSetup() emails a code; emailConfirm() switches it on
 *   once that code comes back - proving the inbox is theirs.
 *
 * Someone on email codes can move to an app at any time (setup again). Until
 * one is confirmed, sign-in doesn't ask for a code. Also: the browsers
 * they've told to remember them, and forgetting them.
 */
class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $user = $request->user();
        // On already - unless it's email codes, and they're moving to an app.
        if ($user->hasAuthenticatorApp()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is already on.']);
        }

        // Reuses a secret that was never confirmed, so opening setup twice
        // (two tabs, a reload) doesn't leave the app with a stale QR code.
        $secret = $user->two_factor_secret;
        if (! $secret) {
            $secret = $twoFactor->newSecret();
            $user->forceFill(['two_factor_secret' => $secret])->save();
        }

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $twoFactor->otpauthUrl($user, $secret),
        ]);
    }

    public function confirm(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || ! $twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Check your authenticator app and try again.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_method' => 'totp'])->save();

        return response()->json(['two_factor' => true, 'method' => 'totp']);
    }

    /** Email codes instead of an app: first, a code to the inbox on file. */
    public function emailSetup(Request $request, EmailCodes $codes): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is already on.']);
        }
        if (! $codes->send($user)) {
            throw ValidationException::withMessages(['code' => 'A code was sent just now. Wait a minute, then ask again.']);
        }

        return response()->json(['sent' => true, 'email' => EmailCodes::mask($user->email)]);
    }

    public function emailConfirm(Request $request, EmailCodes $codes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is already on.']);
        }
        if (! $codes->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Check the latest email from OEM Dealer Hub, or ask for a new code.']);
        }

        // No app secret: an unconfirmed one from an abandoned app setup mustn't count later.
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_method' => 'email', 'two_factor_secret' => null])->save();

        return response()->json(['two_factor' => true, 'method' => 'email']);
    }

    /** Turning it off needs a current code - and isn't allowed where it's required. */
    public function disable(Request $request, TwoFactor $twoFactor, EmailCodes $codes): JsonResponse
    {
        if (config('dealerdock.require_two_factor')) {
            abort(403, 'Two-factor sign-in is required.');
        }

        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        $ok = $user->hasTwoFactor() && (
            ($user->hasAuthenticatorApp() && $twoFactor->verify($user, $user->two_factor_secret, $data['code']))
            || $codes->verify($user, $data['code'])
        );
        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'That code isn\'t right.']);
        }

        $user->clearTwoFactor();

        return response()->json(['two_factor' => false]);
    }

    /** How they sign in, and the browsers they've told to remember them. */
    public function security(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = TrustedBrowser::find($user, $request->cookie(TrustedBrowser::COOKIE));

        return response()->json([
            'two_factor_method' => $user->twoFactorMethod(),
            'email' => EmailCodes::mask($user->email),
            'trusted_browsers' => $user->trustedBrowsers()->where('expires_at', '>', now())->orderByDesc('last_used_at')->get()
                ->map(fn (TrustedBrowser $b) => [
                    'id' => $b->id,
                    'label' => $b->label,
                    'last_used_at' => $b->last_used_at?->toIso8601String(),
                    'expires_at' => $b->expires_at->toIso8601String(),
                    'current' => $current?->id === $b->id,
                ])->values(),
        ]);
    }

    public function forgetBrowser(Request $request, int $browser): JsonResponse
    {
        $request->user()->trustedBrowsers()->whereKey($browser)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    /** "Forget every browser" - for a lost laptop, or a shared computer. */
    public function forgetAllBrowsers(Request $request): JsonResponse
    {
        $request->user()->trustedBrowsers()->delete();

        return response()->json(null, 204)->withoutCookie(TrustedBrowser::COOKIE);
    }
}
