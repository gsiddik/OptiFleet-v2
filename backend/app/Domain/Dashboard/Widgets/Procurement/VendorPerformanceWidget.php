<?php

namespace App\Domain\Dashboard\Widgets\Procurement;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * PR-04 Vendor Performance — per vendor, over posted Goods Receipts received in the period:
 *  on-time rate  = receipts on or before the PO's expected delivery date (tenant-local date)
 *                  ÷ receipts whose PO has an expected delivery date (receipts without one are left out
 *                  of this denominator and counted separately);
 *  accepted rate = Σ accepted qty ÷ Σ (accepted + rejected + damaged) qty of those receipts.
 * Numerator and denominator are always returned with the rate.
 */
class VendorPerformanceWidget extends Widget
{
    public function id(): string
    {
        return 'PR-04';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function unit(): string
    {
        return 'mixed';
    }

    public function modules(): array
    {
        return ['PROCUREMENT'];
    }

    public function permissions(): array
    {
        return ['purchase_order.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        [$from, $to] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $items = DB::table('goods_receipt_items')->groupBy('goods_receipt_id')
            ->selectRaw('goods_receipt_id, sum(quantity_accepted) as accepted, sum(quantity_accepted + quantity_rejected + quantity_damaged) as received');
        $query = DB::table('goods_receipts as gr')
            ->join('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')
            ->leftJoin('partners as pa', 'pa.id', '=', 'gr.partner_id')
            ->leftJoinSub($items, 'gi', 'gi.goods_receipt_id', '=', 'gr.id')
            ->where('gr.tenant_id', $context->tenantId)->where('gr.status', 'POSTED')
            ->where('gr.received_at', '>=', $from)->where('gr.received_at', '<', $to);
        $context->scopeWarehouse($query, 'gr.warehouse_id');
        $localDate = $context->localDateSql('gr.received_at');

        $rows = $query->groupBy('gr.partner_id', 'pa.name')
            ->selectRaw("gr.partner_id, pa.name as vendor_name, count(*) as receipts,
                count(*) filter (where po.expected_delivery_date is not null) as with_due,
                count(*) filter (where po.expected_delivery_date is not null and {$localDate} <= po.expected_delivery_date) as on_time,
                coalesce(sum(gi.accepted), 0) as accepted, coalesce(sum(gi.received), 0) as received")
            ->orderByDesc('receipts')->orderBy('pa.name')->get();

        return ['data' => ['vendors' => $rows->map(fn ($r) => [
            'partner_id' => $r->partner_id, 'vendor_name' => $r->vendor_name, 'receipts' => (int) $r->receipts,
            'on_time' => (int) $r->on_time, 'with_due_date' => (int) $r->with_due, 'without_due_date' => (int) $r->receipts - (int) $r->with_due,
            'on_time_rate' => (int) $r->with_due > 0 ? round(100 * (int) $r->on_time / (int) $r->with_due, 1) : null,
            'accepted_qty' => self::decimal($r->accepted), 'received_qty' => self::decimal($r->received),
            'accepted_rate' => (float) $r->received > 0 ? round(100 * (float) $r->accepted / (float) $r->received, 1) : null,
        ])->all()]];
    }
}
