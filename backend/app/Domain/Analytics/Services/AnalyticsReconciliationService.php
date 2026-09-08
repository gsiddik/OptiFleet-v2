<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Identity\Models\Tenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 61 — independently re-derives a handful of critical
 * aggregate counts straight from PostgreSQL and compares them against
 * the corresponding Mongo daily_* document for the same tenant/date,
 * reporting any mismatch beyond config('analytics.reconciliation')
 * tolerance rather than silently trusting the projection.
 */
class AnalyticsReconciliationService
{
    public function __construct(private readonly BusinessDateResolver $businessDates) {}

    public function reconcile(string $tenantId, string $businessDate): array
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);
        [$start, $end] = $this->businessDates->utcBoundsForBusinessDate($tenant, $businessDate);
        $tolerance = (int) config('analytics.reconciliation.count_tolerance', 0);

        $checks = [
            $this->check(
                'work_order_completed_count',
                WorkOrder::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereBetween('completed_at', [$start, $end])->count(),
                (int) DB::connection('mongodb')->table('daily_work_order_metrics')
                    ->where(['tenant_id' => $tenantId, 'snapshot_date' => $businessDate, 'workshop_id' => null])
                    ->value('completed'),
                $tolerance,
            ),
            $this->check(
                'breakdown_reported_count',
                DB::table('breakdowns')->where('tenant_id', $tenantId)->whereBetween('reported_at', [$start, $end])->count(),
                (int) DB::connection('mongodb')->table('daily_breakdown_metrics')
                    ->where(['tenant_id' => $tenantId, 'snapshot_date' => $businessDate, 'branch_id' => null])
                    ->value('breakdown_count'),
                $tolerance,
            ),
            $this->check(
                'purchase_order_created_count',
                DB::table('purchase_orders')->where('tenant_id', $tenantId)->whereBetween('created_at', [$start, $end])->count(),
                (int) DB::connection('mongodb')->table('daily_procurement_metrics')
                    ->where(['tenant_id' => $tenantId, 'snapshot_date' => $businessDate, 'branch_id' => null])
                    ->value('po_count'),
                $tolerance,
            ),
            $this->check(
                'warranty_claims_submitted_count',
                DB::table('warranty_claims')->where('tenant_id', $tenantId)->whereBetween('created_at', [$start, $end])->count(),
                (int) DB::connection('mongodb')->table('daily_warranty_metrics')
                    ->where(['tenant_id' => $tenantId, 'snapshot_date' => $businessDate, 'branch_id' => null])
                    ->value('claims_submitted'),
                $tolerance,
            ),
        ];

        return [
            'tenant_id' => $tenantId,
            'business_date' => $businessDate,
            'checks' => $checks,
            'has_material_mismatch' => collect($checks)->contains(fn ($c) => ! $c['matches']),
        ];
    }

    private function check(string $name, int $postgresCount, int $mongoCount, int $tolerance): array
    {
        $diff = abs($postgresCount - $mongoCount);

        return [
            'check' => $name,
            'postgres_count' => $postgresCount,
            'mongo_count' => $mongoCount,
            'difference' => $postgresCount - $mongoCount,
            'matches' => $diff <= $tolerance,
        ];
    }
}
