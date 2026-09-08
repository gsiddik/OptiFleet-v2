<?php

namespace App\Providers;

use App\Domain\Intelligence\Labels\LabelBuilderRegistry;
use App\Domain\Intelligence\Risk\RiskScorerRegistry;
use App\Domain\Intelligence\Services\FeatureDatasetRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 7: registers every feature extractor once, mirroring
 * AnalyticsServiceProvider (Section 79 — reuse infrastructure, don't
 * duplicate the registration pattern).
 */
class IntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureDatasetRegistry::class, function ($app) {
            $registry = new FeatureDatasetRegistry;

            foreach ($this->extractorClasses() as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });

        $this->app->singleton(LabelBuilderRegistry::class, function ($app) {
            $registry = new LabelBuilderRegistry;
            foreach ($this->labelBuilderClasses() as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });

        $this->app->singleton(RiskScorerRegistry::class, function ($app) {
            $registry = new RiskScorerRegistry;
            foreach ($this->riskScorerClasses() as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });
    }

    /** @return class-string[] */
    private function riskScorerClasses(): array
    {
        return [
            \App\Domain\Intelligence\Risk\DeterministicVehicleFailureRiskScorer::class,
        ];
    }

    /** @return class-string[] */
    private function labelBuilderClasses(): array
    {
        return [
            \App\Domain\Intelligence\Labels\VehicleFailureLabelBuilder::class,
        ];
    }

    /** @return class-string[] */
    private function extractorClasses(): array
    {
        return [
            \App\Domain\Intelligence\Extractors\VehicleFeatureExtractor::class,
            \App\Domain\Intelligence\Extractors\ComponentFeatureExtractor::class,
            \App\Domain\Intelligence\Extractors\TireFeatureExtractor::class,
        ];
    }
}
