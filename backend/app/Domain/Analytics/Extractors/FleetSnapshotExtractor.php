<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 18 — daily_fleet_snapshots, one document per
 * (tenant, snapshot_date, branch) plus one tenant-wide rollup document
 * (branch_id = null).
 *
 * KNOWN LIMITATION (documented, not hidden): PostgreSQL Phase 1-5 does
 * not retain a point-in-time vehicle-status history table, only the
 * vehicle's *current* status. This extractor therefore always reflects
 * status as of ETL execution time — running it for a past business_date
 * (backfill) re-labels the current distribution under that date rather
 * than reconstructing history that was never captured. This is a
 * limitation of the available source data, not of the ETL logic, and is
 * consistent for every tenant.
 *
 * Formulas:
 *   total_vehicles      = count(status != DISPOSED, not soft-deleted)
 *   availability.percentage = active_count / total_vehicles * 100
 *     (ACTIVE is "available"; total_vehicles is "planned" fleet size —
 *      DISPOSED vehicles are excluded from both.)
 */
class FleetSnapshotExtractor implements DatasetExtractor
{
    private const STATUSES = ['ACTIVE', 'IN_MAINTENANCE', 'BREAKDOWN', 'OUT_OF_SERVICE', 'INACTIVE'];

    public function __construct(private readonly AnalyticsUpsertWriter $writer) {}

    public function key(): string
    {
        return 'fleet_snapshot';
    }

    public function label(): string
    {
        return 'Fleet Snapshot';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $result = new EtlDatasetResult;
        $snapshotDate = $businessDate->format('Y-m-d');

        $rows = Vehicle::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'DISPOSED')
            ->select('branch_id', 'status', DB::raw('count(*) as cnt'))
            ->groupBy('branch_id', 'status')
            ->get();

        $result->sourceCount = (int) $rows->sum('cnt');

        $branchIds = $rows->pluck('branch_id')->unique()->values();
        $byBranch = $rows->groupBy('branch_id');

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $rows);

        foreach ($branchIds as $branchId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $branchId, $byBranch->get($branchId, collect()));
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_fleet_snapshots', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $branchId, $rows): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + (int) $row->cnt;
        }

        $total = array_sum($counts);
        $available = $counts['ACTIVE'];
        $percentage = $total > 0 ? round(($available / $total) * 100, 2) : 0.0;

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'branch_id' => $branchId,
            'total_vehicles' => $total,
            'active_vehicles' => $counts['ACTIVE'],
            'in_maintenance' => $counts['IN_MAINTENANCE'],
            'breakdown' => $counts['BREAKDOWN'],
            'out_of_service' => $counts['OUT_OF_SERVICE'],
            'inactive' => $counts['INACTIVE'],
            'availability' => [
                'available_count' => $available,
                'planned_count' => $total,
                'percentage' => $percentage,
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'branch_id' => $branchId],
            'doc' => $doc,
        ];
    }
}
