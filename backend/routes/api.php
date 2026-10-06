<?php

use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['request.locale', 'throttle:10,1']);

    Route::middleware(['auth:sanctum', 'tenant.context', 'request.locale', 'throttle:120,1'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me/preferences', [AuthController::class, 'updatePreferences']);
        Route::post('/auth/switch-tenant', [AuthController::class, 'switchTenant']);

        require __DIR__.'/api/platform.php';
        require __DIR__.'/api/app.php';
    });
});
