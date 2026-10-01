<?php

namespace App\Domain\Partner\Services;

use App\Domain\Partner\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Type-aware vendor KPIs computed from the operational transactions themselves (not from the
 * performance event log), one aggregate query per block — no per-row loading, no N+1.
 *
 * The period selects a cohort by its START event, so every rate is computed over the same set:
 *   EXTERNAL_WORKSHOP          Work Orders whose Work Authorization Letter was issued to the
 *                              workshop in the period (`wal_generated_at`)
 *   SUPPLIER types             Purchase Orders ordered in the period (`order_date`)
 *   TOWING / OTHER_SERVICE     External services requested in the period (`requested_at`)
 * Money is summed in SQL `numeric` and returned as a 2-decimal string (never float).
 * Every query is constrained to the partner's tenant.
 */
class VendorPerformanceService
{
    public const SUPPLIER_TYPES = ['SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER'];

    public const SERVICE_TYPES = ['TOWING_PROVIDER', 'OTHER_SERVICE_PROVIDER'];

    public function summary(Partner $partner, ?string $from = null, ?string $to = null): array
    {
        $toDate = $to ? CarbonImmutable::parse($to)->endOfDay() : CarbonImmutable::now()->endOfDay();
        $fromDate = $from ? CarbonImmutable::parse($from)->startOfDay() : $toDate->subMonthsNoOverflow(12)->addDay()->startOfDay();

        $category = match (true) {
            $partner->partner_type === 'EXTERNAL_WORKSHOP' => 'EXTERNAL_WORKSHOP',
            in_array($partner->partner_type, self::SUPPLIER_TYPES, true) => 'SUPPLIER',
            default => 'SERVICE_PROVIDER',
        };

        $kpis = match ($category) {
            'EXTERNAL_WORKSHOP' => $this->externalWorkshop($partner, $fromDate, $toDate),
            'SUPPLIER' => $this->supplier($partner, $fromDate, $toDate),
            default => $this->serviceProvider($partner, $fromDate, $toDate),
        };

        return [
            'category' => $category,
            'partner_type' => $partner->partner_type,
            'period' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString(), 'basis' => $this->basis($category)],
            'kpis' => $kpis,
        ];
    }

    private function basis(string $category): string
    {
        return match ($category) {
            'EXTERNAL_WORKSHOP' => 'Work Orders assigned (Work Authorization Letter issued) in the period',
            'SUPPLIER' => 'Purchase Orders ordered in the period',
            default => 'External services requested in the period',
        };
    }

    private function externalWorkshop(Partner $partner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('work_order_external_invoices')
            ->where('tenant_id', $partner->tenant_id)
            ->where('wal_workshop_partner_id', $partner->id)
            ->whereBetween('wal_generated_at', [$from, $to])
            ->selectRaw(<<<'SQL'
                count(*) as assigned,
                count(acknowledged_at) as acknowledged,
                count(completed_at) as completed,
                count(*) filter (where status = 'CANCELLED') as cancelled,
                count(*) filter (where status = 'PAID') as paid_count,
                round(avg(extract(epoch from (acknowledged_at - coalesce(delivered_at, wal_generated_at)))) / 3600, 1) as avg_ack_hours,
                round(avg(extract(epoch from (completed_at - acknowledged_at))) / 3600, 1) as avg_completion_hours,
                round(coalesce(sum(vendor_invoice_amount) filter (where completed_at is not null and status <> 'CANCELLED'), 0), 2)::text as invoiced,
                round(coalesce(sum(paid_amount) filter (where status = 'PAID'), 0), 2)::text as paid,
                round(coalesce(sum(vendor_invoice_amount) filter (where status = 'BILLED'), 0), 2)::text as outstanding
            SQL)
            ->first();

        $assigned = (int) $row->assigned;

        return [
            'work_orders_assigned' => $assigned,
            'acknowledged' => (int) $row->acknowledged,
            // The External Workshop workflow has no "rejected by workshop" state: a WAL that is
            // not accepted is cancelled (counted below), so rejection is not tracked separately.
            'rejected' => null,
            'completed' => (int) $row->completed,
            'cancelled' => (int) $row->cancelled,
            'acknowledgement_rate' => $this->rate((int) $row->acknowledged, $assigned),
            'completion_rate' => $this->rate((int) $row->completed, $assigned),
            'cancellation_rate' => $this->rate((int) $row->cancelled, $assigned),
            'avg_acknowledgement_hours' => $row->avg_ack_hours !== null ? (float) $row->avg_ack_hours : null,
            'avg_completion_hours' => $row->avg_completion_hours !== null ? (float) $row->avg_completion_hours : null,
            'invoice_amount' => $this->money($row->invoiced),
            'paid_amount' => $this->money($row->paid),
            'outstanding_amount' => $this->money($row->outstanding),
            'invoices_paid' => (int) $row->paid_count,
        ];
    }

    private function supplier(Partner $partner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pos = DB::table('purchase_orders')
            ->where('tenant_id', $partner->tenant_id)
            ->where('partner_id', $partner->id)
            ->whereBetween(DB::raw('coalesce(order_date, created_at::date)'), [$from->toDateString(), $to->toDateString()]);

        // First posted receipt per PO, to judge on-time delivery against the expected date.
        $firstReceipt = DB::table('goods_receipts')
            ->where('tenant_id', $partner->tenant_id)
            ->where('status', 'POSTED')
            ->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, min(received_at) as first_received_at');

        $po = (clone $pos)
            ->leftJoinSub($firstReceipt, 'fr', 'fr.purchase_order_id', '=', 'purchase_orders.id')
            ->selectRaw(<<<'SQL'
                count(*) filter (where status in ('ISSUED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED')) as issued,
                count(*) filter (where status = 'CANCELLED') as cancelled,
                count(*) filter (where status in ('RECEIVED', 'CLOSED')) as fully_received,
                count(fr.first_received_at) as delivered,
                count(fr.first_received_at) filter (where expected_delivery_date is not null and fr.first_received_at::date <= expected_delivery_date) as on_time,
                count(fr.first_received_at) filter (where expected_delivery_date is not null and fr.first_received_at::date > expected_delivery_date) as late,
                round(avg(fr.first_received_at::date - order_date), 1) as avg_lead_days,
                round(coalesce(sum(total) filter (where status in ('ISSUED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED')), 0), 2)::text as po_value
            SQL)
            ->first();

        $poIds = (clone $pos)->select('id');

        $qty = DB::table('goods_receipt_items')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->where('goods_receipts.tenant_id', $partner->tenant_id)
            ->where('goods_receipts.status', 'POSTED')
            ->whereIn('goods_receipts.purchase_order_id', $poIds)
            ->selectRaw('coalesce(sum(quantity_accepted), 0) as accepted, coalesce(sum(quantity_rejected), 0) as rejected, coalesce(sum(quantity_damaged), 0) as damaged')
            ->first();

        $paidPerInvoice = DB::table('vendor_invoice_payments')
            ->where('tenant_id', $partner->tenant_id)
            ->groupBy('vendor_invoice_reference_id')
            ->selectRaw('vendor_invoice_reference_id, sum(amount) as paid');

        $inv = DB::table('vendor_invoice_references')
            ->leftJoinSub($paidPerInvoice, 'p', 'p.vendor_invoice_reference_id', '=', 'vendor_invoice_references.id')
            ->where('vendor_invoice_references.tenant_id', $partner->tenant_id)
            ->where('vendor_invoice_references.partner_id', $partner->id)
            ->whereIn('vendor_invoice_references.purchase_order_id', $poIds)
            ->selectRaw(<<<'SQL'
                count(*) as invoices,
                round(coalesce(sum(amount), 0), 2)::text as invoiced,
                round(coalesce(sum(p.paid), 0), 2)::text as paid,
                round(coalesce(sum(amount) filter (where p.paid is null), 0), 2)::text as outstanding
            SQL)
            ->first();

        $accepted = (string) $qty->accepted;
        $rejected = (string) $qty->rejected;
        $receivedTotal = (float) $accepted + (float) $rejected;
        $judged = (int) $po->on_time + (int) $po->late;

        return [
            'purchase_orders_issued' => (int) $po->issued,
            'purchase_orders_cancelled' => (int) $po->cancelled,
            'purchase_orders_fully_received' => (int) $po->fully_received,
            'purchase_order_value' => $this->money($po->po_value),
            'deliveries' => (int) $po->delivered,
            'deliveries_on_time' => (int) $po->on_time,
            'deliveries_late' => (int) $po->late,
            'on_time_rate' => $this->rate((int) $po->on_time, $judged),
            'avg_lead_time_days' => $po->avg_lead_days !== null ? (float) $po->avg_lead_days : null,
            'quantity_accepted' => $this->quantity($accepted),
            'quantity_rejected' => $this->quantity($rejected),
            'quantity_damaged' => $this->quantity((string) $qty->damaged),
            'rejection_rate' => $receivedTotal > 0 ? round((float) $rejected / $receivedTotal * 100, 1) : null,
            'invoices' => (int) $inv->invoices,
            'invoice_amount' => $this->money($inv->invoiced),
            'paid_amount' => $this->money($inv->paid),
            'outstanding_amount' => $this->money($inv->outstanding),
        ];
    }

    private function serviceProvider(Partner $partner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $services = DB::table('work_order_external_services')
            ->where('tenant_id', $partner->tenant_id)
            ->where('partner_id', $partner->id)
            ->whereBetween('requested_at', [$from, $to]);

        $row = (clone $services)->selectRaw(<<<'SQL'
                count(*) as requested,
                count(completed_at) as completed,
                count(*) filter (where status = 'CANCELLED') as cancelled,
                round(avg(extract(epoch from (completed_at - requested_at))) / 3600, 1) as avg_completion_hours,
                round(coalesce(sum(cost) filter (where status <> 'CANCELLED'), 0), 2)::text as estimated_cost
            SQL)->first();

        $paidPerInvoice = DB::table('workshop_invoice_payments')
            ->where('tenant_id', $partner->tenant_id)
            ->groupBy('workshop_invoice_id')
            ->selectRaw('workshop_invoice_id, sum(paid_amount) as paid');

        $inv = DB::table('workshop_invoices')
            ->leftJoinSub($paidPerInvoice, 'p', 'p.workshop_invoice_id', '=', 'workshop_invoices.id')
            ->where('workshop_invoices.tenant_id', $partner->tenant_id)
            ->where('workshop_invoices.partner_id', $partner->id)
            ->whereNull('workshop_invoices.deleted_at')
            ->where('workshop_invoices.status', '<>', 'CANCELLED')
            ->whereIn('workshop_invoices.work_order_external_service_id', (clone $services)->select('id'))
            ->selectRaw(<<<'SQL'
                count(*) as invoices,
                round(coalesce(sum(total_amount), 0), 2)::text as invoiced,
                round(coalesce(sum(p.paid), 0), 2)::text as paid,
                round(coalesce(sum(total_amount) filter (where p.paid is null), 0), 2)::text as outstanding
            SQL)
            ->first();

        $requested = (int) $row->requested;

        return [
            'services_requested' => $requested,
            'services_completed' => (int) $row->completed,
            'services_cancelled' => (int) $row->cancelled,
            'completion_rate' => $this->rate((int) $row->completed, $requested),
            'cancellation_rate' => $this->rate((int) $row->cancelled, $requested),
            'avg_completion_hours' => $row->avg_completion_hours !== null ? (float) $row->avg_completion_hours : null,
            'estimated_cost' => $this->money($row->estimated_cost),
            'invoices' => (int) $inv->invoices,
            'invoice_amount' => $this->money($inv->invoiced),
            'paid_amount' => $this->money($inv->paid),
            'outstanding_amount' => $this->money($inv->outstanding),
        ];
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    /** Money is rounded half-up to 2 decimals in SQL (`round(numeric, 2)::text`); never a float. */
    private function money(mixed $value): string
    {
        return (string) $value;
    }

    private function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
