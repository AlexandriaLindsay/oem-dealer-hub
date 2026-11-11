<?php

namespace App\Providers;

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
    }

    public function boot(): void
    {
        // At least 10 characters; in production also not in a known breach
        // (Have I Been Pwned - an outside call, so not in tests).
        Password::defaults(fn () => app()->isProduction() ? Password::min(10)->uncompromised() : Password::min(10));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Reset and invite links go to the SPA's page, not a Laravel route.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));
    }
}
