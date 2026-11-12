<?php

namespace App\Providers;

use App\Billing\BillingGateway;
use App\Billing\StripeGateway;
use App\Billing\SyncPlanFromWebhook;
use App\Models\Manufacturer;
use App\Support\Scanning\ClamAvScanner;
use App\Support\Scanning\NoScanner;
use App\Support\Scanning\Scanner;
use App\Tenancy\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty Tenant for every request and queued job.
        $this->app->scoped(Tenant::class);

        $this->app->singleton(BillingGateway::class, StripeGateway::class);

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
        // Without the secret, Cashier accepts unsigned webhooks - anyone could "pay" for an account.
        if (app()->isProduction() && config('cashier.secret') && ! config('cashier.webhook.secret')) {
            throw new \RuntimeException('Production needs STRIPE_WEBHOOK_SECRET so Stripe webhooks are verified.');
        }

        // The bot check guards sign-up, sign-in and password reset in production.
        if (app()->isProduction() && ! config('services.turnstile.secret')) {
            throw new \RuntimeException('Production needs TURNSTILE_SITE_KEY and TURNSTILE_SECRET (Cloudflare Turnstile).');
        }

        // The manufacturer pays, not a person.
        Cashier::useCustomerModel(Manufacturer::class);
        // A failed payment doesn't cut anyone off while Stripe retries it; the
        // owner sees a banner. If Stripe gives up, the subscription ends and
        // the account lapses (SyncPlan).
        Cashier::keepPastDueSubscriptionsActive();
        Event::listen(WebhookHandled::class, SyncPlanFromWebhook::class);

        // At least 10 characters; in production also not in a known breach
        // (Have I Been Pwned - an outside call, so not in tests).
        Password::defaults(fn () => app()->isProduction() ? Password::min(10)->uncompromised() : Password::min(10));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // The stock check link in a dealer's email (no sign-in): plenty for a person, a wall for guessing tokens.
        RateLimiter::for('stock-check-link', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // "In stock near you": shoppers on a manufacturer's site. Generous per visitor; the page also caches for 5 minutes.
        RateLimiter::for('public-stock', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        // The integration API (/api/v1): per key, generous for a nightly sync, a ceiling for a runaway loop.
        // Before the key is checked, so by its hash - or the address, when there's no key.
        RateLimiter::for('api-keys', fn (Request $request) => Limit::perMinute(120)->by($request->bearerToken() ? 'key:'.hash('sha256', $request->bearerToken()) : 'ip:'.$request->ip()));
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
