<?php

namespace App\Providers;

use App\Domain\Governance\CentralGovernance;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        // One resolver per application instance, so the short in-process memo of OptiNexus answers is shared (and flushable).
        $this->app->singleton(CentralGovernance::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
