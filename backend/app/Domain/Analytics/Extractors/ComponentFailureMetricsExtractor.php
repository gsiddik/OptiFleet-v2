<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\AnalyticsValidator;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 36 — daily_component_failure_metrics, one document per
 * (tenant, snapshot_date, component_group) plus a tenant-wide rollup.
 * This is the dataset Section 36 calls out as most important for Phase
 * 7 — every figure here is descriptive/historical, no prediction.
 *
 * failure_count    = component_removals with disposition = SCRAP,
 *   removed_at in [start,end), for assets in this component_group.
 * failure_rate     = failure_count / count(assets ever installed in this
 *   group), guarded against a zero denominator.
 * repeat_failure   = component_assets in this group with more than one
 *   removal (any disposition) in the trailing 90 days ending at the
 *   business date (same lookback convention as Vehicle Health).
 * mean_mileage_to_failure = avg(removal_odometer - installation_odometer)
 *   over the window's SCRAP removals.
 * repair_vs_replace_ratio = count(component_repairs completed in window)
 *   / count(SCRAP removals in window), guarded.
 * replacement_frequency   = failure_count (alias, Section 36's own name
 *   for the same figure).
 * cost_by_component = sum(component_repairs.cost in window) +
 *   sum(purchase_cost of assets installed as replacements in window).
 *
 * NOT computed: mean_hours_to_failure — no engine-hour-at-installation
 * column exists on component_installations, only odometer.
 */
class ComponentFailureMetricsExtractor implements DatasetExtractor
{
    private const REPEAT_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'component_failure_metrics';
    }

    public function label(): string
    {
        return 'Component Failure Metrics';
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
        $repeatWindowStart = $end->subDays(self::REPEAT_LOOKBACK_DAYS);

        $groupIds = DB::table('component_assets')->where('tenant_id', $tenantId)->whereNotNull('component_group_id')->distinct()->pluck('component_group_id');
        $result->sourceCount = DB::table('component_assets')->where('tenant_id', $tenantId)->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, null, $start, $end, $repeatWindowStart);
        foreach ($groupIds as $groupId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $groupId, $groupId, $start, $end, $repeatWindowStart);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_component_failure_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $groupId, ?string $filterGroupId, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $repeatWindowStart): array
    {
        $assetIds = DB::table('component_assets')->where('tenant_id', $tenantId)
            ->when($filterGroupId, fn ($q) => $q->where('component_group_id', $filterGroupId))
            ->pluck('id');

        $scrapRemovals = DB::table('component_removals')
            ->whereIn('component_asset_id', $assetIds)
            ->where('disposition', 'SCRAP')
            ->whereBetween('removed_at', [$start, $end])
            ->get(['component_asset_id', 'removal_odometer']);

        $installedEverCount = DB::table('component_installations')->whereIn('component_asset_id', $assetIds)->distinct('component_asset_id')->count('component_asset_id');

        $mileages = DB::table('component_removals as cr')
            ->join('component_installations as ci', 'cr.component_installation_id', '=', 'ci.id')
            ->whereIn('cr.component_asset_id', $assetIds)
            ->where('cr.disposition', 'SCRAP')
            ->whereBetween('cr.removed_at', [$start, $end])
            ->whereNotNull('cr.removal_odometer')->whereNotNull('ci.installation_odometer')
            ->get(['cr.removal_odometer', 'ci.installation_odometer'])
            ->map(fn ($r) => max(0, $r->removal_odometer - $r->installation_odometer));

        $repeatFailures = DB::table('component_removals')
            ->whereIn('component_asset_id', $assetIds)
            ->whereBetween('removed_at', [$repeatWindowStart, $end])
            ->select('component_asset_id', DB::raw('count(*) as cnt'))
            ->groupBy('component_asset_id')
            ->having(DB::raw('count(*)'), '>', 1)
            ->count();

        $repairsInWindow = DB::table('component_repairs')->whereIn('component_asset_id', $assetIds)->whereBetween('completed_at', [$start, $end])->count();
        $repairCost = (float) DB::table('component_repairs')->whereIn('component_asset_id', $assetIds)->whereBetween('completed_at', [$start, $end])->sum('cost');

        $replacementCost = (float) DB::table('component_installations as ci')
            ->join('component_assets as ca', 'ci.component_asset_id', '=', 'ca.id')
            ->whereIn('ci.component_asset_id', $assetIds)
            ->whereBetween('ci.installed_at', [$start, $end])
            ->sum('ca.purchase_cost');

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'component_group_id' => $groupId,
            'failure_count' => $scrapRemovals->count(),
            'failure_rate_percentage' => AnalyticsValidator::safeDivide($scrapRemovals->count() * 100, (float) $installedEverCount),
            'repeat_failure' => $repeatFailures,
            'mean_mileage_to_failure' => $mileages->isNotEmpty() ? round($mileages->avg(), 1) : null,
            'repair_vs_replace_ratio' => AnalyticsValidator::safeDivide($repairsInWindow, (float) $scrapRemovals->count()),
            'replacement_frequency' => $scrapRemovals->count(),
            'cost_by_component' => round($repairCost + $replacementCost, 4),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'component_group_id' => $groupId],
            'doc' => $doc,
        ];
    }
}
