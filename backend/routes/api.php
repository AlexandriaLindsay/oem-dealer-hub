<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Tenant\DashboardController;
use App\Http\Controllers\Api\Tenant\DealerController;
use App\Http\Controllers\Api\Tenant\FleetController;
use App\Http\Controllers\Api\Tenant\MachineController;
use App\Http\Controllers\Api\Tenant\ProductLineController;
use App\Http\Controllers\Api\Tenant\ProductModelController;
use App\Http\Controllers\Api\Tenant\RegistrationController;
use App\Http\Controllers\Api\Tenant\StaffController;
use App\Http\Controllers\Api\TwoFactorController;
use Illuminate\Support\Facades\Route;

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

    // Everything inside one manufacturer. ResolveTenant checks the user works
    // there and scopes every tenant-owned query to it.
    Route::prefix('m/{tenant}')->middleware(['two-factor', 'tenant'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->middleware('feature:dashboard');

        Route::get('/product-lines', [ProductLineController::class, 'index'])->middleware('feature:products');
        Route::post('/product-lines', [ProductLineController::class, 'store'])->middleware('feature:manage_products');

        Route::get('/product-models', [ProductModelController::class, 'index'])->middleware('feature:products');
        Route::post('/product-models', [ProductModelController::class, 'store'])->middleware('feature:manage_products');
        Route::post('/product-models/{model}/options', [ProductModelController::class, 'storeOption'])->middleware('feature:manage_products');

        Route::get('/machines', [MachineController::class, 'index'])->middleware('feature:machines');
        Route::post('/machines', [MachineController::class, 'store'])->middleware('feature:manage_machines');
        Route::get('/machines/{machine}', [MachineController::class, 'show'])->middleware('feature:machines');
        Route::post('/machines/{machine}/status', [MachineController::class, 'updateStatus'])->middleware('feature:machine_status');
        Route::post('/machines/{machine}/transfer', [MachineController::class, 'transfer'])->middleware('feature:manage_machines');

        Route::get('/registrations', [RegistrationController::class, 'index'])->middleware('feature:registrations');
        Route::post('/registrations', [RegistrationController::class, 'store'])->middleware(['feature:register_machines', 'throttle:uploads']);
        Route::get('/registrations/{registration}', [RegistrationController::class, 'show'])->middleware('feature:registrations');
        Route::post('/registrations/{registration}/approve', [RegistrationController::class, 'approve'])->middleware('feature:review_registrations');
        Route::post('/registrations/{registration}/reject', [RegistrationController::class, 'reject'])->middleware('feature:review_registrations');
        Route::get('/registrations/{registration}/documents/{document}', [RegistrationController::class, 'document'])->middleware('feature:registrations');

        Route::get('/fleet', [FleetController::class, 'summary'])->middleware('feature:fleet_report');
        Route::get('/fleet/export', [FleetController::class, 'export'])->middleware('feature:fleet_report');

        Route::get('/dealers', [DealerController::class, 'index'])->middleware('feature:dealers');
        Route::post('/dealers', [DealerController::class, 'store'])->middleware('feature:manage_dealers');

        Route::get('/staff', [StaffController::class, 'index'])->middleware('feature:manage_staff');
        Route::post('/staff', [StaffController::class, 'store'])->middleware('feature:manage_staff');
    });
});
