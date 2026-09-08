<?php

namespace App\Providers;

use App\Domain\Analytics\Services\DatasetRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 6: registers every DatasetExtractor once, in one place, so Jobs
 * and CLI commands never hard-code a list of extractor classes. Adding a
 * new analytics dataset (Batches C-F) means adding one ->register() call
 * here.
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatasetRegistry::class, function ($app) {
            $registry = new DatasetRegistry;

            foreach ($this->extractorClasses() as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });
    }

    /** @return class-string[] */
    private function extractorClasses(): array
    {
        return [
            \App\Domain\Analytics\Extractors\FleetSnapshotExtractor::class,
            \App\Domain\Analytics\Extractors\VehicleHealthExtractor::class,
            \App\Domain\Analytics\Extractors\MaintenanceMetricsExtractor::class,
            \App\Domain\Analytics\Extractors\WorkOrderMetricsExtractor::class,
            \App\Domain\Analytics\Extractors\BreakdownMetricsExtractor::class,
            // Batches D-F append their extractor classes here.
        ];
    }
}
