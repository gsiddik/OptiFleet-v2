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
 * Phase 6 Section 37 — daily_warranty_metrics, one document per
 * (tenant, snapshot_date, branch — resolved via the claim's vehicle)
 * plus a tenant-wide rollup.
 *
 * claims_submitted = count(created_at in [start,end)) — WarrantyClaim has
 *   no dedicated submitted_at column, so creation is used as the
 *   submission event.
 * approved/rejected = count(status = APPROVED|REJECTED, reviewed_at in
 *   [start,end)) — reviewed_at is the one status-transition timestamp
 *   the model records.
 * settled          = count(settled_at in [start,end)).
 * claim_value      = sum(claim_amount) for claims submitted in the
 *   window.
 * warranty_utilization_percentage = settled / claims_submitted x100,
 *   guarded against a zero denominator.
 * failure_within_warranty = claims submitted in the window that
 *   reference an actual Warranty record (warranty_id not null) vs. ones
 *   that don't (out-of-warranty claims, if the business records those
 *   too).
 *
 * NOT computed: recovered_value — Phase 1-5 has no field distinguishing
 * an amount actually recovered from a vendor/manufacturer from the
 * claim_amount itself (Section 39: only expose what the source data
 * supports).
 */
class WarrantyMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'warranty_metrics';
    }

    public function label(): string
    {
        return 'Warranty Metrics';
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
        $result->sourceCount = DB::table('warranty_claims')->where('tenant_id', $tenantId)->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, null, $start, $end);
        foreach ($branchIds as $branchId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $branchId, $branchId, $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_warranty_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $branchId, ?string $filterBranchId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $vehicleIds = $filterBranchId
            ? Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('branch_id', $filterBranchId)->pluck('id')
            : null;

        $claimsQuery = fn () => DB::table('warranty_claims')->where('tenant_id', $tenantId)
            ->when($vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds));

        $submitted = (clone $claimsQuery())->whereBetween('created_at', [$start, $end])->get(['id', 'claim_amount', 'warranty_id']);
        $approved = (clone $claimsQuery())->where('status', 'APPROVED')->whereBetween('reviewed_at', [$start, $end])->count();
        $rejected = (clone $claimsQuery())->where('status', 'REJECTED')->whereBetween('reviewed_at', [$start, $end])->count();
        $settled = (clone $claimsQuery())->whereBetween('settled_at', [$start, $end])->count();

        $withWarrantyLink = $submitted->filter(fn ($c) => $c->warranty_id !== null)->count();

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'branch_id' => $branchId,
            'claims_submitted' => $submitted->count(),
            'approved' => $approved,
            'rejected' => $rejected,
            'settled' => $settled,
            'claim_value' => round((float) $submitted->sum('claim_amount'), 4),
            'recovered_value' => null,
            'warranty_utilization_percentage' => AnalyticsValidator::safeDivide($settled * 100, (float) $submitted->count()),
            'failure_within_warranty' => $withWarrantyLink,
            'failure_outside_warranty' => $submitted->count() - $withWarrantyLink,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'branch_id' => $branchId],
            'doc' => $doc,
        ];
    }
}
