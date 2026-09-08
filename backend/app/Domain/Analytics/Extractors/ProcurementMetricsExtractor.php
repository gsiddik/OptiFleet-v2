<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 31 — daily_procurement_metrics, one document per
 * (tenant, snapshot_date, branch) plus a tenant-wide rollup. Branch is
 * resolved via the Purchase Order's delivery_warehouse_id -> Warehouse
 * (Purchase Orders have no branch_id of their own).
 *
 * pr_count / po_count = count(created_at in [start,end)).
 * po_value            = sum(total) for POs created in the window.
 * open_po             = count(status not in RECEIVED/CLOSED/REJECTED/
 *   CANCELLED) — point-in-time balance.
 * partial_receipt     = count(status = PARTIALLY_RECEIVED), point-in-time.
 * late_receipt        = goods receipts posted in the window whose
 *   received_at is after the PO's expected_delivery_date.
 * rejection_damage_quantity = sum(quantity_rejected + quantity_damaged)
 *   over goods_receipt_items posted in the window.
 * procurement_lead_time_days = avg(received_at - PO.order_date) over
 *   goods receipts posted in the window (Section 31's "procurement lead
 *   time" — vendor-specific lead time is computed again, per vendor, in
 *   VendorMetricsExtractor).
 *
 * Price variance (Section 31 "where derivable") is computed once, at the
 * vendor level, in VendorMetricsExtractor — a branch has no single
 * "price" to vary against; a vendor's quoted vs. ordered price does.
 */
class ProcurementMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'procurement_metrics';
    }

    public function label(): string
    {
        return 'Procurement Metrics';
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

        $branchIds = Warehouse::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNotNull('branch_id')->distinct()->pluck('branch_id');
        $result->sourceCount = DB::table('purchase_orders')->where('tenant_id', $tenantId)->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $start, $end);
        foreach ($branchIds as $branchId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $branchId, $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_procurement_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $branchId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $warehouseIds = $branchId
            ? Warehouse::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->pluck('id')
            : null;

        $prQuery = DB::table('purchase_requests')->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $prCount = (clone $prQuery)->whereBetween('created_at', [$start, $end])->count();

        $poQuery = DB::table('purchase_orders')->where('tenant_id', $tenantId)
            ->when($warehouseIds, fn ($q) => $q->whereIn('delivery_warehouse_id', $warehouseIds));

        $poCount = (clone $poQuery)->whereBetween('created_at', [$start, $end])->count();
        $poValue = (float) (clone $poQuery)->whereBetween('created_at', [$start, $end])->sum('total');
        $openPo = (clone $poQuery)->whereNotIn('status', ['RECEIVED', 'CLOSED', 'REJECTED', 'CANCELLED'])->count();
        $partialReceipt = (clone $poQuery)->where('status', 'PARTIALLY_RECEIVED')->count();

        $poIds = (clone $poQuery)->pluck('id');

        $receipts = DB::table('goods_receipts as gr')
            ->join('purchase_orders as po', 'gr.purchase_order_id', '=', 'po.id')
            ->whereIn('gr.purchase_order_id', $poIds)
            ->where('gr.status', 'POSTED')
            ->whereBetween('gr.received_at', [$start, $end])
            ->select('gr.id', 'gr.received_at', 'po.order_date', 'po.expected_delivery_date')
            ->get();

        $lateReceipts = $receipts->filter(function ($r) {
            if (! $r->expected_delivery_date || ! $r->received_at) {
                return false;
            }

            return CarbonImmutable::parse($r->received_at)->gt(CarbonImmutable::parse($r->expected_delivery_date)->endOfDay());
        })->count();
        $leadTimeDays = $receipts->filter(fn ($r) => $r->order_date)
            ->map(fn ($r) => CarbonImmutable::parse($r->order_date)->diffInDays(CarbonImmutable::parse($r->received_at)));

        $rejectionDamageQty = (float) DB::table('goods_receipt_items as gri')
            ->join('goods_receipts as gr', 'gri.goods_receipt_id', '=', 'gr.id')
            ->whereIn('gr.purchase_order_id', $poIds)
            ->whereBetween('gr.received_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(gri.quantity_rejected + gri.quantity_damaged), 0) as total')
            ->value('total');

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'branch_id' => $branchId,
            'pr_count' => $prCount,
            'po_count' => $poCount,
            'po_value' => round($poValue, 4),
            'open_po' => $openPo,
            'partial_receipt' => $partialReceipt,
            'late_receipt' => $lateReceipts,
            'rejection_damage_quantity' => $rejectionDamageQty,
            'procurement_lead_time_days' => $leadTimeDays->isNotEmpty() ? round($leadTimeDays->avg(), 1) : null,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'branch_id' => $branchId],
            'doc' => $doc,
        ];
    }
}
