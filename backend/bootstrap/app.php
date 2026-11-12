<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireCurrentTerms;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\RequireGoodStanding;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA signs in with Sanctum's session cookie.
        $middleware->statefulApi();

        // Stripe's webhooks can't carry a CSRF token; Cashier checks their signature instead.
        $middleware->validateCsrfTokens(except: ['stripe/*']);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'two-factor' => RequireTwoFactor::class,
            'feature' => RequireFeature::class,
            'standing' => RequireGoodStanding::class,
            'terms' => RequireCurrentTerms::class,
            'api-key' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
