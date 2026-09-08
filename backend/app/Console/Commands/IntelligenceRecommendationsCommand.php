<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\PredictionRunService;
use App\Domain\Intelligence\Services\RecommendationGenerationService;
use App\Domain\Intelligence\Services\RecommendationReviewService;
use Illuminate\Console\Command;

/**
 * Phase 7 Section 29-30, 38 — generates recommendations from that day's
 * predictions/insights, then expires anything past its due date with no
 * decision. Scheduled after intelligence:diagnostics.
 *   intelligence:recommendations --tenant=<uuid> --date=2026-09-06
 */
class IntelligenceRecommendationsCommand extends Command
{
    protected $signature = 'intelligence:recommendations
        {--date= : Business date (Y-m-d). Defaults to "yesterday" in each tenant\'s own timezone.}
        {--tenant= : Restrict to a single tenant ID.}';

    protected $description = 'Generate Phase 7 prescriptive recommendations and expire overdue ones.';

    public function handle(RecommendationGenerationService $generator, RecommendationReviewService $review, PredictionRunService $tenants): int
    {
        foreach ($tenants->eligibleTenants($this->option('tenant')) as $tenant) {
            $businessDate = $this->option('date') ?: now()->subDay()->format('Y-m-d');
            $created = $generator->generateForTenant($tenant->id, $businessDate);
            $expired = $review->expireOverdue($tenant->id);
            $this->line("tenant={$tenant->id} created={$created} expired={$expired}");
        }

        $this->info('Recommendation generation completed.');

        return self::SUCCESS;
    }
}
