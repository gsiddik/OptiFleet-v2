<?php

namespace App\Domain\Intelligence\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 4-5, 23: one document per (vehicle, component_group)
 * pair currently installed as of the feature date's end-of-day boundary
 * — the same temporal-cutoff discipline as VehicleFeatureExtractor
 * (Section 67). A component_group with no currently-installed asset for
 * a vehicle has nothing to score and is skipped, not zero-filled.
 */
class ComponentFeatureExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'component_features';
    }

    public function label(): string
    {
        return 'Component Features';
    }

    public function version(): string
    {
        return config('intelligence.feature_set_versions.component', 'v1');
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $result = new EtlDatasetResult;
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);
        $featureDate = $businessDate->format('Y-m-d');
        [, $end] = $this->businessDates->utcBoundsForBusinessDate($tenant, $featureDate);
        $lookbackDays = (int) config('intelligence.feature_lookback_days', 90);
        $windowStart = $end->subDays($lookbackDays);

        $installed = DB::table('component_installations as ci')
            ->join('component_assets as ca', 'ci.component_asset_id', '=', 'ca.id')
            ->where('ca.tenant_id', $tenantId)
            ->where('ci.installed_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ci.removed_at')->orWhere('ci.removed_at', '>', $end))
            ->whereNotNull('ca.component_group_id')
            ->select('ci.vehicle_id', 'ca.component_group_id', 'ci.installation_odometer', 'ci.installed_at', 'ca.purchase_date', 'ca.id as component_asset_id')
            ->get();

        $result->sourceCount = $installed->count();

        $currentOdometers = DB::table('vehicles')->where('tenant_id', $tenantId)->pluck('current_odometer', 'id');

        $documents = [];
        foreach ($installed as $row) {
            $currentOdometer = (float) ($currentOdometers[$row->vehicle_id] ?? 0);
            $usageKm = $row->installation_odometer !== null ? round($currentOdometer - (float) $row->installation_odometer, 2) : null;
            $installedAt = CarbonImmutable::parse($row->installed_at);
            $ageDays = $installedAt->diffInDays($end);

            $historicalRemovals = DB::table('component_removals as cr')
                ->join('component_installations as ci2', 'cr.component_installation_id', '=', 'ci2.id')
                ->join('component_assets as ca2', 'cr.component_asset_id', '=', 'ca2.id')
                ->where('ci2.vehicle_id', $row->vehicle_id)
                ->where('ca2.component_group_id', $row->component_group_id)
                ->whereBetween('cr.removed_at', [$windowStart, $end])
                ->get(['cr.disposition']);

            $doc = [
                'tenant_id' => $tenantId,
                'feature_date' => $featureDate,
                'vehicle_id' => $row->vehicle_id,
                'component_group_id' => $row->component_group_id,
                'component_asset_id' => $row->component_asset_id,
                'feature_set_version' => $this->version(),
                'source_data_as_of' => $end->toIso8601String(),
                'age_days' => $ageDays,
                'usage_km' => $usageKm,
                'failure_count_90d' => $historicalRemovals->whereIn('disposition', ['SCRAP', 'REPAIR'])->count(),
                'replacement_count_90d' => $historicalRemovals->where('disposition', 'SCRAP')->count(),
                'repair_count_90d' => $historicalRemovals->where('disposition', 'REPAIR')->count(),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ];

            $documents[] = [
                'key' => ['tenant_id' => $tenantId, 'feature_date' => $featureDate, 'vehicle_id' => $row->vehicle_id, 'component_group_id' => $row->component_group_id],
                'doc' => $doc,
            ];
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('component_daily_features', $documents, $result);

        return $result;
    }
}
