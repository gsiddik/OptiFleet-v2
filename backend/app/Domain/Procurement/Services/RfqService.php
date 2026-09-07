<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Invoice\Services\NumberSequenceService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Models\RfqItem;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Procurement\Models\VendorQuotationItem;
use Illuminate\Support\Facades\DB;

/**
 * Section 17/18/19: an RFQ may go to several vendors; each vendor's
 * quotation is priced by them but every total is recalculated server-side
 * (Section 18: "Backend must calculate comparison totals. Do not trust
 * client totals") — the client only ever supplies unit_price/discount/tax
 * per line, never a line or document total.
 */
class RfqService
{
    public function __construct(private readonly NumberSequenceService $numbers) {}

    public function create(Warehouse $warehouse, array $attributes, array $items, ?PurchaseRequest $purchaseRequest = null): Rfq
    {
        if (empty($items)) {
            throw new ProcurementException('An RFQ needs at least one item.');
        }

        return DB::transaction(function () use ($warehouse, $attributes, $items, $purchaseRequest) {
            $number = sprintf('RFQ/%d/%06d', (int) now()->format('Y'), $this->numbers->next('rfq', (int) now()->format('Y')));

            $rfq = Rfq::query()->create(array_merge($attributes, [
                'tenant_id' => $warehouse->tenant_id,
                'rfq_number' => $number,
                'warehouse_id' => $warehouse->id,
                'purchase_request_id' => $purchaseRequest?->id,
                'status' => 'DRAFT',
            ]));

            foreach ($items as $line) {
                RfqItem::query()->create(['rfq_id' => $rfq->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity']]);
            }

            return $rfq->fresh('items');
        });
    }

    public function inviteVendors(Rfq $rfq, array $partnerIds): Rfq
    {
        return DB::transaction(function () use ($rfq, $partnerIds) {
            foreach ($partnerIds as $partnerId) {
                $rfq->vendors()->syncWithoutDetaching([$partnerId => ['invited_at' => now()]]);
            }
            if ($rfq->status === 'DRAFT') {
                $rfq->update(['status' => 'ISSUED', 'issue_date' => $rfq->issue_date ?? now()->toDateString()]);
            }

            return $rfq->fresh('vendors');
        });
    }

    public function close(Rfq $rfq): Rfq
    {
        if ($rfq->status !== 'ISSUED') {
            throw new ProcurementException('Only an issued RFQ can be closed.');
        }
        $rfq->update(['status' => 'CLOSED']);

        return $rfq->fresh();
    }

    public function cancel(Rfq $rfq): Rfq
    {
        if (in_array($rfq->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new ProcurementException("Cannot cancel an RFQ already {$rfq->status}.");
        }
        $rfq->update(['status' => 'CANCELLED']);

        return $rfq->fresh();
    }

    /**
     * @param array<array{rfq_item_id?:string,product_id:string,quantity:float,unit_price:float,discount_percent?:float,tax_percent?:float}> $items
     */
    public function submitQuotation(Rfq $rfq, Partner $partner, array $attributes, array $items): VendorQuotation
    {
        if (empty($items)) {
            throw new ProcurementException('A quotation needs at least one item.');
        }

        return DB::transaction(function () use ($rfq, $partner, $attributes, $items) {
            $quotation = VendorQuotation::query()->updateOrCreate(
                ['rfq_id' => $rfq->id, 'partner_id' => $partner->id],
                array_merge($attributes, [
                    'tenant_id' => $rfq->tenant_id,
                    'status' => 'SUBMITTED',
                    'submitted_at' => now(),
                ])
            );

            $quotation->items()->delete();

            $subtotal = 0.0;
            $taxTotal = 0.0;
            foreach ($items as $line) {
                $qty = (float) $line['quantity'];
                $unitPrice = (float) $line['unit_price'];
                $discountPercent = (float) ($line['discount_percent'] ?? 0);
                $taxPercent = (float) ($line['tax_percent'] ?? 0);

                $base = $qty * $unitPrice;
                $afterDiscount = $base * (1 - $discountPercent / 100);
                $tax = $afterDiscount * ($taxPercent / 100);
                $lineTotal = round($afterDiscount + $tax, 4);

                VendorQuotationItem::query()->create([
                    'vendor_quotation_id' => $quotation->id,
                    'rfq_item_id' => $line['rfq_item_id'] ?? null,
                    'product_id' => $line['product_id'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discountPercent,
                    'tax_percent' => $taxPercent,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $afterDiscount;
                $taxTotal += $tax;
            }

            $freight = (float) ($attributes['freight_cost'] ?? 0);
            $quotation->update([
                'subtotal' => round($subtotal, 4),
                'tax_total' => round($taxTotal, 4),
                'total' => round($subtotal + $taxTotal + $freight, 4),
            ]);

            return $quotation->fresh('items');
        });
    }

    /** Section 19: comparable metrics per quotation for a manual vendor-selection decision. */
    public function compare(Rfq $rfq): array
    {
        return $rfq->quotations()->with(['partner', 'items'])->get()->map(fn (VendorQuotation $q) => [
            'quotation_id' => $q->id,
            'partner' => $q->partner->only(['id', 'name', 'code']),
            'total' => (float) $q->total,
            'lead_time_days' => $q->lead_time_days,
            'payment_terms' => $q->payment_terms,
            'validity_date' => $q->validity_date,
            'item_count' => $q->items->count(),
            'status' => $q->status,
        ])->sortBy('total')->values()->all();
    }

    public function selectVendor(VendorQuotation $selected): VendorQuotation
    {
        return DB::transaction(function () use ($selected) {
            $rfq = Rfq::query()->lockForUpdate()->findOrFail($selected->rfq_id);

            VendorQuotation::query()->where('rfq_id', $rfq->id)->where('id', '!=', $selected->id)->update(['status' => 'REJECTED']);
            $selected->update(['status' => 'SELECTED']);
            $rfq->update(['status' => 'CLOSED']);

            return $selected->fresh(['partner', 'items']);
        });
    }
}
