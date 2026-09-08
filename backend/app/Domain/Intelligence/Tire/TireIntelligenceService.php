<?php

namespace App\Domain\Intelligence\Tire;

use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 34: product/brand performance and abnormal-wear
 * detection built from the Batch A tire_daily_features feature store —
 * the latest snapshot per tenant, grouped by product (Section 32:
 * "keep statistical evidence visible").
 */
class TireIntelligenceService
{
    /** @return array<int, array{product_id: string, tire_count: int, avg_cost_per_km: ?float, avg_damage_count_90d: float}> */
    public function productPerformance(string $tenantId): array
    {
        $latestDate = DB::connection('mongodb')->table('tire_daily_features')
            ->where('tenant_id', $tenantId)->orderByDesc('feature_date')->value('feature_date');

        if (! $latestDate) {
            return [];
        }

        $docs = DB::connection('mongodb')->table('tire_daily_features')
            ->where('tenant_id', $tenantId)->where('feature_date', $latestDate)->get();

        $byProduct = collect($docs)->groupBy('product_id');

        return $byProduct->map(function ($group, $productId) {
            $withCost = $group->pluck('cost_per_km')->filter(fn ($v) => $v !== null);

            return [
                'product_id' => $productId,
                'tire_count' => $group->count(),
                'avg_cost_per_km' => $withCost->isNotEmpty() ? round($withCost->avg(), 4) : null,
                'avg_damage_count_90d' => round($group->pluck('damage_count_90d')->avg(), 2),
            ];
        })->values()->all();
    }

    /** Tires whose damage_count_90d is well above the tenant's own fleet average — abnormal wear (Section 34), not a fault claim. */
    public function abnormalWearTires(string $tenantId, float $zThreshold = 2.0): array
    {
        $latestDate = DB::connection('mongodb')->table('tire_daily_features')
            ->where('tenant_id', $tenantId)->orderByDesc('feature_date')->value('feature_date');
        if (! $latestDate) {
            return [];
        }

        $docs = collect(DB::connection('mongodb')->table('tire_daily_features')
            ->where('tenant_id', $tenantId)->where('feature_date', $latestDate)->get());

        if ($docs->count() < 5) {
            return []; // too few tires for a meaningful fleet comparison
        }

        $values = $docs->pluck('damage_count_90d')->map(fn ($v) => (float) $v);
        $mean = $values->avg();
        $stddev = sqrt($values->map(fn ($v) => ($v - $mean) ** 2)->avg());
        if ($stddev <= 0) {
            return [];
        }

        return $docs->filter(fn ($d) => (((float) $d->damage_count_90d - $mean) / $stddev) >= $zThreshold)
            ->map(fn ($d) => ['tire_id' => $d->tire_id, 'vehicle_id' => $d->vehicle_id, 'damage_count_90d' => $d->damage_count_90d, 'fleet_mean' => round($mean, 2)])
            ->values()->all();
    }
}
