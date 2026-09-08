<?php

namespace Tests\Concerns;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Shared test fixture (Section 69: a small deterministic dataset, not a
 * huge synthetic one) for anything needing several days of real
 * vehicle_daily_features history. Runs the actual VehicleFeatureExtractor
 * for each day — same production code path, not a parallel fake — kept
 * to the minimum days/vehicles that reliably produces at least one
 * elapsed-horizon positive label so tests stay fast.
 */
trait BuildsIntelligenceHistory
{
    /** @return array{0: array<\App\Domain\Vehicle\Models\Vehicle>, 1: \App\Domain\Vehicle\Models\Vehicle} */
    protected function buildVehicleFailureHistory(Tenant $tenant, Branch $branch, VehicleCategory $category, int $vehicleCount = 8, int $days = 20): array
    {
        $runner = app(FeatureRunService::class);
        // Oldest feature date is (days + 35) ago, newest is 35 days ago —
        // every row's 30-day horizon has already elapsed relative to now.
        $start = CarbonImmutable::now()->subDays($days + 35);

        $vehicles = [];
        for ($i = 0; $i < $vehicleCount; $i++) {
            $vehicles[] = $this->makeVehicle($tenant, $branch, $category, ['current_odometer' => 10000 + $i * 500]);
        }

        $lemon = null;
        foreach ($vehicles as $i => $vehicle) {
            if ($i % 3 === 0) {
                $lemon ??= $vehicle;
                Breakdown::query()->create([
                    'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
                    'reported_at' => $start->addDays((int) ($days / 2)), 'severity' => 'MAJOR',
                    'description' => 'synthetic', 'status' => 'REPORTED',
                ]);
            }
        }

        for ($d = 0; $d < $days; $d++) {
            $runner->runDataset($tenant->id, 'vehicle_features', $start->addDays($d)->format('Y-m-d'), 'test');
        }

        return [$vehicles, $lemon];
    }
}
