<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\SsoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['request.locale', 'throttle:10,1']);

    // OptiNexus single sign-on (disabled unless OPTINEXUS_ENABLED=true).
    Route::get('/auth/sso/status', [SsoController::class, 'status']);
    Route::get('/auth/sso/redirect', [SsoController::class, 'redirect'])->middleware('throttle:20,1');
    Route::get('/auth/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:20,1');
    Route::post('/auth/sso/backchannel-logout', [SsoController::class, 'backchannelLogout'])->middleware('throttle:120,1');
    Route::post('/auth/sso/exchange', [SsoController::class, 'exchange'])->middleware(['request.locale', 'throttle:20,1']);

    Route::middleware(['auth:sanctum', 'tenant.context', 'request.locale', 'throttle:120,1'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me/preferences', [AuthController::class, 'updatePreferences']);
        Route::post('/auth/switch-tenant', [AuthController::class, 'switchTenant']);

        require __DIR__.'/api/platform.php';
        require __DIR__.'/api/app.php';
    });
});
