<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Partner\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 32 — daily_vendor_metrics, one document per
 * (tenant, snapshot_date, vendor). No opaque blended "vendor score" is
 * computed (Section 32 explicitly warns against this) — every figure
 * below is one deterministic, independently-checkable ratio.
 *
 * total_purchases        = sum(PO.total) for this vendor's POs created
 *   in [start,end).
 * avg_lead_time_days      = avg(goods_receipt.received_at -
 *   PO.order_date) over this vendor's receipts posted in the window.
 * on_time_delivery_rate   = receipts on/before PO.expected_delivery_date
 *   / total receipts in the window, x100.
 * rejected_quantity_rate  = (rejected+damaged qty) / (accepted+rejected+
 *   damaged qty) over goods_receipt_items posted in the window, x100.
 * fulfillment_rate        = sum(quantity_received) / sum(quantity_ordered)
 *   over line items of this vendor's POs that received activity in the
 *   window, x100 — guarded against a zero denominator (Section 34).
 * avg_price_variance_percentage = avg((PO item unit_price - matching
 *   vendor_quotation item unit_price) / quotation unit_price) x100,
 *   only over PO items whose PO references the vendor_quotation they
 *   were priced from (Section 32 "where meaningful" — unquoted POs are
 *   excluded rather than guessed at).
 */
class VendorMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'vendor_metrics';
    }

    public function label(): string
    {
        return 'Vendor Metrics';
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

        $vendorIds = Partner::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
        $result->sourceCount = $vendorIds->count();

        $documents = [];
        foreach ($vendorIds as $vendorId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $vendorId, $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_vendor_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, string $vendorId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $poQuery = fn () => DB::table('purchase_orders')->where('tenant_id', $tenantId)->where('partner_id', $vendorId);

        $totalPurchases = (float) (clone $poQuery())->whereBetween('created_at', [$start, $end])->sum('total');

        $poIds = (clone $poQuery())->pluck('id');

        $receipts = DB::table('goods_receipts as gr')
            ->join('purchase_orders as po', 'gr.purchase_order_id', '=', 'po.id')
            ->whereIn('gr.purchase_order_id', $poIds)
            ->where('gr.status', 'POSTED')
            ->whereBetween('gr.received_at', [$start, $end])
            ->select('gr.received_at', 'po.order_date', 'po.expected_delivery_date')
            ->get();

        $leadTimes = $receipts->filter(fn ($r) => $r->order_date)
            ->map(fn ($r) => CarbonImmutable::parse($r->order_date)->diffInDays(CarbonImmutable::parse($r->received_at)));

        $onTime = $receipts->filter(fn ($r) => $r->expected_delivery_date
            && CarbonImmutable::parse($r->received_at)->lte(CarbonImmutable::parse($r->expected_delivery_date)->endOfDay()))->count();

        $itemTotals = DB::table('goods_receipt_items as gri')
            ->join('goods_receipts as gr', 'gri.goods_receipt_id', '=', 'gr.id')
            ->whereIn('gr.purchase_order_id', $poIds)
            ->whereBetween('gr.received_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(gri.quantity_accepted),0) as accepted, COALESCE(SUM(gri.quantity_rejected),0) as rejected, COALESCE(SUM(gri.quantity_damaged),0) as damaged')
            ->first();

        $rejectedTotal = (float) $itemTotals->rejected + (float) $itemTotals->damaged;
        $itemDenominator = (float) $itemTotals->accepted + $rejectedTotal;

        $fulfillment = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'poi.purchase_order_id', '=', 'po.id')
            ->whereIn('poi.purchase_order_id', $poIds)
            ->whereBetween('po.updated_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(poi.quantity_received),0) as received, COALESCE(SUM(poi.quantity_ordered),0) as ordered')
            ->first();

        $priceVarianceRows = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'poi.purchase_order_id', '=', 'po.id')
            ->join('vendor_quotation_items as vqi', function ($join) {
                $join->on('vqi.vendor_quotation_id', '=', 'po.vendor_quotation_id')
                    ->on('vqi.product_id', '=', 'poi.product_id');
            })
            ->whereIn('poi.purchase_order_id', $poIds)
            ->whereNotNull('po.vendor_quotation_id')
            ->whereBetween('po.created_at', [$start, $end])
            ->where('vqi.unit_price', '>', 0)
            ->select('poi.unit_price as po_price', 'vqi.unit_price as quoted_price')
            ->get();

        $priceVariances = $priceVarianceRows->map(fn ($r) => (($r->po_price - $r->quoted_price) / $r->quoted_price) * 100);

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'vendor_id' => $vendorId,
            'total_purchases' => round($totalPurchases, 4),
            'lead_time' => [
                'sample_size' => $leadTimes->count(),
                'total_days' => round($leadTimes->sum(), 2),
                'avg_days' => $leadTimes->isNotEmpty() ? round($leadTimes->avg(), 1) : null,
            ],
            // Section 55: every *_percentage field keeps its numerator/
            // denominator alongside it so a date-range query can sum the
            // raw counts and recompute the rate, rather than (incorrectly)
            // averaging daily percentages.
            'on_time_delivery' => [
                'on_time_count' => $onTime,
                'total_receipts' => $receipts->count(),
                'rate_percentage' => $receipts->count() > 0 ? round(($onTime / $receipts->count()) * 100, 2) : null,
            ],
            'rejected_quantity' => [
                'rejected_qty' => $rejectedTotal,
                'total_qty' => $itemDenominator,
                'rate_percentage' => $itemDenominator > 0 ? round(($rejectedTotal / $itemDenominator) * 100, 2) : null,
            ],
            'fulfillment' => [
                'received_qty' => (float) $fulfillment->received,
                'ordered_qty' => (float) $fulfillment->ordered,
                'rate_percentage' => $fulfillment->ordered > 0 ? round(($fulfillment->received / $fulfillment->ordered) * 100, 2) : null,
            ],
            'price_variance' => [
                'sample_size' => $priceVariances->count(),
                'avg_percentage' => $priceVariances->isNotEmpty() ? round($priceVariances->avg(), 2) : null,
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'vendor_id' => $vendorId],
            'doc' => $doc,
        ];
    }
}
