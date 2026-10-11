<?php

namespace App\Providers;

use App\Domain\Integration\AccountingFeed\AccountingFeedProjector;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * OA6 accounting feed wiring, kept in one provider so the feature adds a single registration line:
 * the /api/v1/integration route group and the listener that turns each new integration outbox row into a
 * sequenced feed event inside the same transaction. The outbox service and WorkshopInvoiceService are untouched.
 */
class AccountingFeedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1/integration')
            ->group(fn () => $this->loadRoutesFrom(base_path('routes/api/integration.php')));

        IntegrationOutboxEvent::created(fn (IntegrationOutboxEvent $row) => $this->app->make(AccountingFeedProjector::class)->projectSafely($row));
    }
}
