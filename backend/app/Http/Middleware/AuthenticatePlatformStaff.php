<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Auth\AuthenticationException;

/**
 * Who gets into the staff console (/admin).
 *
 * The console has no sign-in page of its own. Staff sign in through the app,
 * so they get the same bot check and the same replay-proof two-factor as
 * everyone else, and the console reads that session.
 *
 * - Signed out: to the app's sign-in, which comes back here afterwards.
 * - Staff without two-factor yet: to the app's two-factor setup.
 * - Anyone else: a plain 404, so the console doesn't announce itself.
 */
class AuthenticatePlatformStaff extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if (! $user) {
            throw new AuthenticationException('Unauthenticated.', $guards, self::appUrl('/login?next=console'));
        }

        abort_unless($user->is_platform_staff, 404);

        // An authenticator app specifically: email codes aren't enough for a console that reaches every account.
        // (Except locally with two-factor switched off: User::consoleSkipsTwoFactor.)
        if (! $user->hasAuthenticatorApp() && ! $user::consoleSkipsTwoFactor()) {
            throw new AuthenticationException('Two-factor required.', $guards, self::appUrl('/setup-2fa'));
        }

        parent::authenticate($request, $guards);
    }

    private static function appUrl(string $path): string
    {
        return rtrim(config('app.frontend_url'), '/').$path;
    }
}
