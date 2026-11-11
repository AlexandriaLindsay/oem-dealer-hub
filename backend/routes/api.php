<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LegalController;
use App\Http\Controllers\Api\Tenant\BillingController;
use App\Http\Controllers\Api\Tenant\DashboardController;
use App\Http\Controllers\Api\Tenant\DealerController;
use App\Http\Controllers\Api\Tenant\DocumentController;
use App\Http\Controllers\Api\Tenant\FleetController;
use App\Http\Controllers\Api\Tenant\MachineController;
use App\Http\Controllers\Api\Tenant\MachineImportController;
use App\Http\Controllers\Api\Tenant\ProductLineController;
use App\Http\Controllers\Api\Tenant\ProductModelController;
use App\Http\Controllers\Api\Tenant\RegistrationController;
use App\Http\Controllers\Api\Tenant\SetupController;
use App\Http\Controllers\Api\Tenant\StaffController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Public: plans, prices and legal details for the landing, pricing and legal pages.
Route::get('/site', [LegalController::class, 'site']);

// Signing in and up - rate limited per IP (AppServiceProvider).
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/two-factor', [AuthController::class, 'twoFactorChallenge']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/two-factor/setup', [TwoFactorController::class, 'setup']);
    Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:auth');
    Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:auth');

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

            Route::get('/fleet', [FleetController::class, 'summary'])->middleware('feature:fleet_report');
            Route::get('/fleet/export', [FleetController::class, 'export'])->middleware('feature:fleet_report');

            Route::get('/dealers', [DealerController::class, 'index'])->middleware('feature:dealers');
            Route::post('/dealers', [DealerController::class, 'store'])->middleware('feature:manage_dealers');
            Route::post('/dealers/{link}/status', [DealerController::class, 'updateStatus'])->middleware('feature:manage_dealers');

            Route::get('/staff', [StaffController::class, 'index'])->middleware('feature:manage_staff');
            Route::post('/staff', [StaffController::class, 'store'])->middleware('feature:manage_staff');
        });
    });
});
