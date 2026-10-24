<?php

use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use App\Http\Controllers\Api\Integration\AccountingFeedController;
use App\Http\Controllers\Api\Integration\VehicleAvailabilityController;
use App\Http\Middleware\AuthenticateAccountingFeed;
use Illuminate\Support\Facades\Route;

// Machine-to-machine routes under /api/v1/integration (loaded by AccountingFeedServiceProvider).
// No user session and no TenantContextMiddleware: the tenant is bound by the machine credential itself.
Route::middleware(['throttle:60,1', AuthenticateAccountingFeed::class.':accounting.feed.read'])
    ->get('/accounting-feed', [AccountingFeedController::class, 'index'])
    ->name('integration.accounting-feed.index');

Route::middleware(['throttle:120,1', AuthenticateAccountingFeed::class.':'.AccountingFeedCredential::SCOPE_AVAILABILITY])
    ->get('/availability', [VehicleAvailabilityController::class, 'show'])
    ->name('integration.availability.show');
