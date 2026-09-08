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
 * Phase 7 Section 4-5, 34: one document per currently-installed tire, as
 * of the feature date's end-of-day boundary (same temporal-cutoff
 * discipline as the other Section 4 extractors — Section 67).
 */
class TireFeatureExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'tire_features';
    }

    public function label(): string
    {
        return 'Tire Features';
    }

    public function version(): string
    {
        return config('intelligence.feature_set_versions.tire', 'v1');
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $result = new EtlDatasetResult;
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);
        $featureDate = $businessDate->format('Y-m-d');
        [, $end] = $this->businessDates->utcBoundsForBusinessDate($tenant, $featureDate);
        $lookbackDays = (int) config('intelligence.feature_lookback_days', 90);
        $windowStart = $end->subDays($lookbackDays);

        $installed = DB::table('tire_installations as ti')
            ->join('tires as t', 'ti.tire_id', '=', 't.id')
            ->where('t.tenant_id', $tenantId)
            ->where('ti.installed_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ti.removed_at')->orWhere('ti.removed_at', '>', $end))
            ->select('ti.tire_id', 'ti.vehicle_id', 'ti.wheel_position', 'ti.installation_odometer', 'ti.installed_at', 't.product_id', 't.purchase_date', 't.purchase_cost')
            ->get();

        $result->sourceCount = $installed->count();

        $currentOdometers = DB::table('vehicles')->where('tenant_id', $tenantId)->pluck('current_odometer', 'id');

        $documents = [];
        foreach ($installed as $row) {
            $currentOdometer = (float) ($currentOdometers[$row->vehicle_id] ?? 0);
            $usageKm = $row->installation_odometer !== null ? round($currentOdometer - (float) $row->installation_odometer, 2) : null;
            $ageDays = CarbonImmutable::parse($row->installed_at)->diffInDays($end);

            $priorRemovalsSameTire = DB::table('tire_removals')
                ->where('tire_id', $row->tire_id)
                ->whereBetween('removed_at', [$windowStart, $end])
                ->get(['disposition']);

            $costPerKm = ($row->purchase_cost !== null && $usageKm !== null && $usageKm > 0)
                ? round((float) $row->purchase_cost / $usageKm, 4)
                : null;

            $doc = [
                'tenant_id' => $tenantId,
                'feature_date' => $featureDate,
                'tire_id' => $row->tire_id,
                'vehicle_id' => $row->vehicle_id,
                'wheel_position' => $row->wheel_position,
                'product_id' => $row->product_id,
                'feature_set_version' => $this->version(),
                'source_data_as_of' => $end->toIso8601String(),
                'age_days' => $ageDays,
                'usage_km' => $usageKm,
                'cost_per_km' => $costPerKm,
                'damage_count_90d' => $priorRemovalsSameTire->count(),
                'scrap_count_90d' => $priorRemovalsSameTire->where('disposition', 'SCRAP')->count(),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ];

            $documents[] = [
                'key' => ['tenant_id' => $tenantId, 'feature_date' => $featureDate, 'tire_id' => $row->tire_id],
                'doc' => $doc,
            ];
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('tire_daily_features', $documents, $result);

        return $result;
    }
}
