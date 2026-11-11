<?php

namespace App\Providers;

use App\Support\Scanning\ClamAvScanner;
use App\Support\Scanning\NoScanner;
use App\Support\Scanning\Scanner;
use App\Tenancy\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty Tenant for every request and queued job.
        $this->app->scoped(Tenant::class);

        $this->app->singleton(Scanner::class, function () {
            $config = config('dealerdock.scanner');

            return match ($config['driver']) {
                'clamav' => new ClamAvScanner($config['clamav']['host'], $config['clamav']['port'], $config['clamav']['timeout']),
                'none' => new NoScanner,
                default => throw new \InvalidArgumentException('Unknown DEALERDOCK_SCANNER "'.$config['driver'].'" - use clamav or none.'),
            };
        });
    }

    public function boot(): void
    {
        // Uploads are scanned in production, always - fail at boot rather than
        // quietly store unscanned files from a missing setting.
        if (app()->isProduction() && config('dealerdock.scanner.driver') !== 'clamav') {
            throw new \RuntimeException('Production needs DEALERDOCK_SCANNER=clamav: uploads must be scanned for malware.');
        }

        // At least 10 characters; in production also not in a known breach
        // (Have I Been Pwned - an outside call, so not in tests).
        Password::defaults(fn () => app()->isProduction() ? Password::min(10)->uncompromised() : Password::min(10));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // Uploads are scanned and stored - generous for real use, a ceiling for abuse.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        // Serial lookup reaches past the dealer scope (to a model name), so it
        // can't be used to walk every serial number at a manufacturer.
        RateLimiter::for('serial-lookup', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // Reset and invite links go to the SPA's page, not a Laravel route.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));
    }
}
