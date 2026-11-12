<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LegalController;
use App\Http\Controllers\Api\Tenant\BillingController;
use App\Http\Controllers\Api\Tenant\BroadcastController;
use App\Http\Controllers\Api\Tenant\CatalogController;
use App\Http\Controllers\Api\Tenant\DashboardController;
use App\Http\Controllers\Api\Tenant\DealerController;
use App\Http\Controllers\Api\Tenant\DocumentController;
use App\Http\Controllers\Api\Tenant\FleetController;
use App\Http\Controllers\Api\Tenant\IntegrationController;
use App\Http\Controllers\Api\Tenant\MachineController;
use App\Http\Controllers\Api\Tenant\MachineImportController;
use App\Http\Controllers\Api\Tenant\PartsOrderController;
use App\Http\Controllers\Api\Tenant\PriceListController;
use App\Http\Controllers\Api\Tenant\ProductLineController;
use App\Http\Controllers\Api\Tenant\ProductModelController;
use App\Http\Controllers\Api\Tenant\QuoteController;
use App\Http\Controllers\Api\Tenant\RegistrationController;
use App\Http\Controllers\Api\Tenant\SalesOrderController;
use App\Http\Controllers\Api\Tenant\SetupController;
use App\Http\Controllers\Api\Tenant\StaffController;
use App\Http\Controllers\Api\Tenant\TerritoryController;
use App\Http\Controllers\Api\Tenant\UsageController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

// Public: plans, prices and legal details for the landing, pricing and legal pages.
Route::get('/site', [LegalController::class, 'site']);

// The integration API, for a manufacturer's own systems (docs/INTEGRATIONS.md).
// An API key, not a person: the key decides the manufacturer (AuthenticateApiKey).
Route::prefix('v1')->middleware('throttle:api-keys')->group(function () {
    Route::get('/sales-orders', [V1\SalesOrderController::class, 'index'])->middleware('api-key:sales_orders:read');
    Route::get('/sales-orders/{order}', [V1\SalesOrderController::class, 'show'])->middleware('api-key:sales_orders:read');
    Route::patch('/sales-orders/{order}', [V1\SalesOrderController::class, 'update'])->middleware('api-key:sales_orders:write');
    Route::get('/catalog', V1\CatalogController::class)->middleware('api-key:catalog:read');
    Route::post('/machines', [V1\MachineController::class, 'upsert'])->middleware('api-key:machines:write');
    Route::post('/prices', [V1\PriceController::class, 'update'])->middleware('api-key:prices:write');
});

// Signing in and up - rate limited per IP (AppServiceProvider).
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/two-factor', [AuthController::class, 'twoFactorChallenge']);
    Route::post('/login/two-factor/email', [AuthController::class, 'emailCode']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/two-factor/setup', [TwoFactorController::class, 'setup']);
    Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:auth');
    Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:auth');
    // Email codes instead of an app, and the browsers they've remembered.
    Route::post('/two-factor/email', [TwoFactorController::class, 'emailSetup'])->middleware('throttle:auth');
    Route::post('/two-factor/email/confirm', [TwoFactorController::class, 'emailConfirm'])->middleware('throttle:auth');
    Route::get('/account/security', [TwoFactorController::class, 'security']);
    Route::delete('/account/trusted-browsers/{browser}', [TwoFactorController::class, 'forgetBrowser'])->whereNumber('browser');
    Route::delete('/account/trusted-browsers', [TwoFactorController::class, 'forgetAllBrowsers']);

    Route::post('/tours/{key}/seen', [TourController::class, 'seen']);
    Route::post('/terms/accept', [LegalController::class, 'accept']);

    // Everything inside one manufacturer. ResolveTenant checks the user works
    // there and scopes every tenant-owned query to it.
    // 'terms': agreed to the current terms first (RequireCurrentTerms).
    Route::prefix('m/{tenant}')->middleware(['two-factor', 'terms', 'tenant'])->group(function () {
        // Open even while the account is paused, so the owner can pick a plan.
        Route::get('/billing', [BillingController::class, 'show'])->middleware('feature:billing');
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('feature:billing');
        Route::post('/billing/change', [BillingController::class, 'change'])->middleware('feature:billing');
        Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware('feature:billing');
        // Everyone who works with the brand sees its logo - dealers too, and on the "paused" page.
        Route::get('/logo', [SetupController::class, 'logo']);

        // Everything else needs an account in good standing (RequireGoodStanding).
        Route::middleware('standing')->group(function () {
            Route::get('/dashboard', DashboardController::class)->middleware('feature:dashboard');

            Route::get('/onboarding', [SetupController::class, 'onboarding'])->middleware('feature:setup');
            Route::post('/onboarding', [SetupController::class, 'dismiss'])->middleware('feature:setup');
            Route::post('/logo', [SetupController::class, 'storeLogo'])->middleware(['feature:setup', 'throttle:uploads']);
            Route::delete('/logo', [SetupController::class, 'destroyLogo'])->middleware('feature:setup');
            Route::post('/brand', [SetupController::class, 'brand'])->middleware('feature:custom_branding');

            Route::get('/product-lines', [ProductLineController::class, 'index'])->middleware('feature:products');
            Route::post('/product-lines', [ProductLineController::class, 'store'])->middleware('feature:manage_products');

            Route::get('/product-models', [ProductModelController::class, 'index'])->middleware('feature:products');
            Route::post('/product-models', [ProductModelController::class, 'store'])->middleware('feature:manage_products');
            Route::post('/product-models/{model}/options', [ProductModelController::class, 'storeOption'])->middleware('feature:manage_products');

            Route::get('/machines', [MachineController::class, 'index'])->middleware('feature:machines');
            Route::post('/machines', [MachineController::class, 'store'])->middleware('feature:manage_machines');
            // Before /machines/{machine}, so "import" isn't taken as an id.
            Route::get('/machines/import/template', [MachineImportController::class, 'template'])->middleware('feature:import_machines');
            Route::post('/machines/import/preview', [MachineImportController::class, 'preview'])->middleware(['feature:import_machines', 'throttle:uploads']);
            Route::post('/machines/import', [MachineImportController::class, 'store'])->middleware(['feature:import_machines', 'throttle:uploads']);
            Route::get('/machine-imports', [MachineImportController::class, 'index'])->middleware('feature:import_machines');
            Route::get('/machine-imports/{import}/report', [MachineImportController::class, 'report'])->middleware('feature:import_machines');

            Route::get('/machines/{machine}', [MachineController::class, 'show'])->whereNumber('machine')->middleware('feature:machines');
            Route::post('/machines/{machine}/status', [MachineController::class, 'updateStatus'])->middleware('feature:machine_status');
            Route::post('/machines/{machine}/transfer', [MachineController::class, 'transfer'])->middleware('feature:manage_machines');

            Route::get('/registrations', [RegistrationController::class, 'index'])->middleware('feature:registrations');
            Route::post('/registrations', [RegistrationController::class, 'store'])->middleware(['feature:register_machines', 'throttle:uploads']);
            Route::get('/registrations/{registration}', [RegistrationController::class, 'show'])->middleware('feature:registrations');
            Route::post('/registrations/{registration}/approve', [RegistrationController::class, 'approve'])->middleware('feature:review_registrations');
            Route::post('/registrations/{registration}/reject', [RegistrationController::class, 'reject'])->middleware('feature:review_registrations');
            Route::get('/registrations/{registration}/documents/{document}', [RegistrationController::class, 'document'])->middleware('feature:registrations');

            Route::get('/documents', [DocumentController::class, 'index'])->middleware('feature:documents');
            // Before /documents/{document}, so "for-serial" isn't taken as an id.
            Route::get('/documents/for-serial', [DocumentController::class, 'forSerial'])->middleware(['feature:documents', 'throttle:serial-lookup']);
            Route::post('/documents', [DocumentController::class, 'store'])->middleware(['feature:manage_documents', 'throttle:uploads']);
            Route::post('/documents/{document}', [DocumentController::class, 'update'])->whereNumber('document')->middleware(['feature:manage_documents', 'throttle:uploads']);
            Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->middleware('feature:manage_documents');
            Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->whereNumber('document')->middleware('feature:documents');
            Route::get('/machines/{machine}/documents', [DocumentController::class, 'forMachine'])->middleware('feature:documents');

            Route::get('/quotes', [QuoteController::class, 'index'])->middleware('feature:quotes');
            Route::post('/quotes', [QuoteController::class, 'store'])->middleware('feature:build_quotes');
            Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->whereNumber('quote')->middleware('feature:quotes');
            Route::post('/quotes/{quote}', [QuoteController::class, 'update'])->whereNumber('quote')->middleware('feature:build_quotes');
            Route::post('/quotes/{quote}/status', [QuoteController::class, 'status'])->middleware('feature:build_quotes');
            Route::post('/quotes/{quote}/outcome', [QuoteController::class, 'outcome'])->whereNumber('quote')->middleware('feature:close_quotes');
            Route::post('/quotes/{quote}/copy', [QuoteController::class, 'copy'])->middleware('feature:build_quotes');
            Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])->whereNumber('quote')->middleware('feature:build_quotes');

            Route::get('/sales-orders', [SalesOrderController::class, 'index'])->middleware('feature:sales_orders');
            Route::get('/sales-orders/{order}', [SalesOrderController::class, 'show'])->whereNumber('order')->middleware('feature:sales_orders');
            Route::post('/sales-orders/{order}', [SalesOrderController::class, 'update'])->whereNumber('order')->middleware('feature:manage_sales_orders');
            Route::post('/sales-orders/{order}/status', [SalesOrderController::class, 'status'])->whereNumber('order')->middleware('feature:manage_sales_orders');

            // Connecting their ERP: API keys, webhooks, matching numbers (Pro, owner and admin).
            Route::middleware('feature:erp_integration')->group(function () {
                Route::get('/integrations', [IntegrationController::class, 'index']);
                Route::post('/integrations/settings', [IntegrationController::class, 'settings']);
                Route::post('/integrations/api-keys', [IntegrationController::class, 'storeKey']);
                Route::delete('/integrations/api-keys/{key}', [IntegrationController::class, 'revokeKey'])->whereNumber('key');
                Route::post('/integrations/webhooks', [IntegrationController::class, 'storeWebhook']);
                Route::post('/integrations/webhooks/{webhook}', [IntegrationController::class, 'updateWebhook'])->whereNumber('webhook');
                Route::delete('/integrations/webhooks/{webhook}', [IntegrationController::class, 'destroyWebhook'])->whereNumber('webhook');
                Route::post('/integrations/webhooks/{webhook}/rotate', [IntegrationController::class, 'rotateSecret'])->whereNumber('webhook');
                Route::post('/integrations/webhooks/{webhook}/test', [IntegrationController::class, 'testWebhook'])->whereNumber('webhook');
                Route::post('/integrations/deliveries/{delivery}/retry', [IntegrationController::class, 'retryDelivery'])->whereNumber('delivery');
                Route::get('/integrations/mapping', [IntegrationController::class, 'mapping']);
                Route::post('/integrations/mapping', [IntegrationController::class, 'saveMapping']);
            });

            Route::get('/parts-orders', [PartsOrderController::class, 'index'])->middleware('feature:parts_orders');
            Route::get('/parts-orders/defaults', [PartsOrderController::class, 'defaults'])->middleware('feature:order_parts');
            Route::post('/parts-orders', [PartsOrderController::class, 'store'])->middleware('feature:order_parts');
            Route::get('/parts-orders/{order}', [PartsOrderController::class, 'show'])->whereNumber('order')->middleware('feature:parts_orders');
            Route::post('/parts-orders/{order}/status', [PartsOrderController::class, 'update'])->middleware('feature:manage_parts_orders');
            Route::post('/parts-orders/{order}/cancel', [PartsOrderController::class, 'cancel'])->middleware('feature:order_parts');

            Route::get('/broadcasts', [BroadcastController::class, 'index'])->middleware('feature:broadcasts');
            Route::post('/broadcasts', [BroadcastController::class, 'store'])->middleware(['feature:broadcasts', 'throttle:uploads']);
            Route::get('/broadcasts/{broadcast}', [BroadcastController::class, 'show'])->middleware('feature:broadcasts');
            Route::post('/broadcasts/{broadcast}/withdraw', [BroadcastController::class, 'withdraw'])->middleware('feature:broadcasts');
            Route::get('/announcements', [BroadcastController::class, 'announcements'])->middleware('feature:announcements');
            Route::get('/announcements/{broadcast}', [BroadcastController::class, 'announcement'])->middleware('feature:announcements');

            Route::get('/usage', [UsageController::class, 'report'])->middleware('feature:usage_analytics');
            // The app reports each page someone opens; the controller decides whether it counts.
            Route::post('/usage/visits', [UsageController::class, 'visit'])->middleware('throttle:120,1');

            Route::get('/catalog', CatalogController::class)->middleware('feature:catalog');
            Route::get('/price-lists', [PriceListController::class, 'index'])->middleware('feature:manage_pricing');
            Route::post('/price-lists', [PriceListController::class, 'store'])->middleware('feature:manage_pricing');
            Route::get('/price-lists/{priceList}', [PriceListController::class, 'show'])->whereNumber('priceList')->middleware('feature:manage_pricing');
            Route::post('/price-lists/{priceList}', [PriceListController::class, 'update'])->whereNumber('priceList')->middleware('feature:manage_pricing');
            Route::post('/price-lists/{priceList}/prices', [PriceListController::class, 'prices'])->middleware('feature:manage_pricing');
            Route::post('/price-lists/{priceList}/default', [PriceListController::class, 'makeDefault'])->middleware('feature:manage_pricing');
            Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy'])->whereNumber('priceList')->middleware('feature:manage_pricing');
            Route::post('/dealers/{link}/price-list', [PriceListController::class, 'assign'])->middleware('feature:manage_pricing');

            Route::get('/fleet', [FleetController::class, 'summary'])->middleware('feature:fleet_report');
            Route::get('/fleet/export', [FleetController::class, 'export'])->middleware('feature:fleet_report');

            Route::get('/territories', [TerritoryController::class, 'index'])->middleware('feature:territories');
            Route::post('/territories', [TerritoryController::class, 'store'])->middleware('feature:territories');
            Route::patch('/territories/{territory}', [TerritoryController::class, 'update'])->whereNumber('territory')->middleware('feature:territories');
            Route::delete('/territories/{territory}', [TerritoryController::class, 'destroy'])->whereNumber('territory')->middleware('feature:territories');
            Route::put('/territories/{territory}/reps', [TerritoryController::class, 'reps'])->whereNumber('territory')->middleware('feature:territories');
            Route::post('/dealers/{link}/territory', [TerritoryController::class, 'assignDealer'])->middleware('feature:territories');

            Route::get('/dealers', [DealerController::class, 'index'])->middleware('feature:dealers');
            Route::post('/dealers', [DealerController::class, 'store'])->middleware('feature:manage_dealers');
            Route::post('/dealers/{link}/status', [DealerController::class, 'updateStatus'])->middleware('feature:manage_dealers');
            Route::post('/dealers/{link}/product-lines', [DealerController::class, 'productLines'])->middleware('feature:manage_dealers');

            Route::get('/staff', [StaffController::class, 'index'])->middleware('feature:manage_staff');
            Route::post('/staff', [StaffController::class, 'store'])->middleware('feature:manage_staff');
        });
    });
});
