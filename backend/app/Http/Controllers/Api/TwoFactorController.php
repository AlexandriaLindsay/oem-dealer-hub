<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Setting up an authenticator app: setup() makes a secret and shows it as a QR
 * code, confirm() switches it on once the app gives back a right code. Until
 * confirmed, sign-in doesn't ask for a code.
 */
class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
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

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json(['two_factor' => true]);
    }

    /** Turning it off needs a current code - and isn't allowed where it's required. */
    public function disable(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        if (config('dealerdock.require_two_factor')) {
            abort(403, 'Two-factor sign-in is required.');
        }

        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->hasTwoFactor() || ! $twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code isn\'t right.']);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['two_factor' => false]);
    }
}
