<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\AnalyticsValidator;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 35 — daily_tire_metrics, one document per
 * (tenant, snapshot_date, branch) plus a tenant-wide rollup. A tire is
 * bucketed to a branch via its *current* vehicle's branch (Section 54
 * lifecycle basis preserved — see Tire model's own current_status/
 * current_vehicle_id, not a derived state); tires that are warehouse
 * stock only (no current vehicle) appear in the tenant rollup only.
 *
 * tire_count/in_stock/installed/scrapped/retread = current_status
 *   distribution — point-in-time, like the fleet snapshot.
 * average_mileage  = avg(removal_odometer - installation_odometer) over
 *   tire_removals posted in [start,end) for this branch.
 * cost_per_km      = avg(tire.purchase_cost / that tire's mileage) over
 *   the same removals, each guarded against a zero/missing denominator
 *   (Section 34) individually before averaging.
 * replacement_frequency = count(tire_removals in the window).
 * failure_damage_reason = bounded (max 10) removal_reason counts in the
 *   window.
 * usage_by_brand    = bounded (max 10) manufacturer counts among this
 *   branch's currently-installed tires.
 */
class TireMetricsExtractor implements DatasetExtractor
{
    private const TOP_N = 10;

    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'tire_metrics';
    }

    public function label(): string
    {
        return 'Tire Metrics';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $result = new EtlDatasetResult;
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);
        $snapshotDate = $businessDate->format('Y-m-d');
        [$start, $end] = $this->businessDates->utcBoundsForBusinessDate($tenant, $snapshotDate);

        $branchIds = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->distinct()->pluck('branch_id');
        $result->sourceCount = DB::table('tires')->where('tenant_id', $tenantId)->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, null, $start, $end);
        foreach ($branchIds as $branchId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $branchId, $branchId, $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_tire_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $branchId, ?string $filterBranchId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $vehicleIds = $filterBranchId
            ? Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('branch_id', $filterBranchId)->pluck('id')
            : Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');

        $tiresQuery = fn () => DB::table('tires')->where('tenant_id', $tenantId)
            ->when($filterBranchId, fn ($q) => $q->whereIn('current_vehicle_id', $vehicleIds), fn ($q) => $q);

        $statusCounts = (clone $tiresQuery())->select('current_status', DB::raw('count(*) as cnt'))->groupBy('current_status')->pluck('cnt', 'current_status');

        $removals = DB::table('tire_removals as tr')
            ->join('tire_installations as ti', 'tr.tire_installation_id', '=', 'ti.id')
            ->join('tires as t', 'tr.tire_id', '=', 't.id')
            ->when($filterBranchId, fn ($q) => $q->whereIn('ti.vehicle_id', $vehicleIds))
            ->whereBetween('tr.removed_at', [$start, $end])
            ->select('tr.removal_odometer', 'ti.installation_odometer', 'tr.removal_reason', 't.purchase_cost')
            ->get();

        $mileages = $removals->filter(fn ($r) => $r->removal_odometer !== null && $r->installation_odometer !== null)
            ->map(fn ($r) => max(0, $r->removal_odometer - $r->installation_odometer));

        $costPerKmSamples = $removals->map(function ($r) {
            if (! $r->removal_odometer || ! $r->installation_odometer || ! $r->purchase_cost) {
                return null;
            }
            $mileage = $r->removal_odometer - $r->installation_odometer;

            return AnalyticsValidator::safeDivide((float) $r->purchase_cost, $mileage);
        })->filter(fn ($v) => $v !== null);

        $reasons = $removals->countBy('removal_reason')->sortDesc()->take(self::TOP_N)
            ->map(fn ($count, $reason) => ['reason' => $reason, 'count' => $count])->values()->all();

        $usageByBrand = (clone $tiresQuery())
            ->whereIn('current_status', ['INSTALLED', 'IN_USE'])
            ->select('manufacturer', DB::raw('count(*) as cnt'))
            ->groupBy('manufacturer')->orderByDesc('cnt')->limit(self::TOP_N)->get()
            ->map(fn ($row) => ['manufacturer' => $row->manufacturer, 'count' => $row->cnt])->all();

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'branch_id' => $branchId,
            'tire_count' => (int) $statusCounts->sum(),
            'in_stock' => (int) ($statusCounts['IN_STOCK'] ?? 0),
            'installed' => (int) ($statusCounts['INSTALLED'] ?? 0) + (int) ($statusCounts['IN_USE'] ?? 0),
            'scrapped' => (int) ($statusCounts['SCRAPPED'] ?? 0),
            'retread' => (int) ($statusCounts['RETREAD'] ?? 0),
            'average_mileage' => $mileages->isNotEmpty() ? round($mileages->avg(), 1) : null,
            'average_mileage_sample_size' => $mileages->count(),
            'cost_per_km' => $costPerKmSamples->isNotEmpty() ? round($costPerKmSamples->avg(), 4) : null,
            'cost_per_km_sample_size' => $costPerKmSamples->count(),
            'replacement_frequency' => $removals->count(),
            'failure_damage_reason' => $reasons,
            'usage_by_brand' => $usageByBrand,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'branch_id' => $branchId],
            'doc' => $doc,
        ];
    }
}
