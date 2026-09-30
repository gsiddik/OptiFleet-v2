<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Models\RfqItem;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Procurement\Models\VendorQuotationItem;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Section 17/18/19: an RFQ may go to several vendors; each vendor's
 * quotation is priced by them but every total is recalculated server-side
 * (Section 18: "Backend must calculate comparison totals. Do not trust
 * client totals") — the client only ever supplies unit_price/discount/tax
 * per line, never a line or document total.
 */
class RfqService
{
    public function __construct(private readonly DocumentNumberingService $numbers) {}

    public function create(Warehouse $warehouse, array $attributes, array $items, ?PurchaseRequest $purchaseRequest = null): Rfq
    {
        if (empty($items)) {
            throw new ProcurementException('An RFQ needs at least one item.');
        }
        $this->assertOrderableLines($warehouse->tenant_id, $items);

        return DB::transaction(function () use ($warehouse, $attributes, $items, $purchaseRequest) {
            $number = $this->numbers->generate('rfq', $warehouse->tenant_id, null, null, $warehouse->id);

            $rfq = Rfq::query()->create(array_merge($attributes, [
                'tenant_id' => $warehouse->tenant_id,
                'rfq_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
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

    /**
     * Every RFQ line must be an ACTIVE product of the RFQ's tenant (or a platform product), listed
     * once, with a positive quantity (whole number unless the product's UOM is measured).
     */
    private function assertOrderableLines(string $tenantId, array $items): void
    {
        $seen = [];
        foreach (array_values($items) as $i => $line) {
            $product = Product::query()->withoutGlobalScopes()->find($line['product_id'] ?? null);
            if (! $product || ($product->tenant_id !== null && $product->tenant_id !== $tenantId) || $product->status !== 'ACTIVE') {
                throw ValidationException::withMessages(["items.{$i}.product_id" => 'Select an active product of this tenant.']);
            }
            if (isset($seen[$product->id])) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => "\"{$product->name}\" is listed more than once."]);
            }
            $seen[$product->id] = true;
            if (! is_numeric($line['quantity'] ?? null) || (float) $line['quantity'] <= 0) {
                throw ValidationException::withMessages(["items.{$i}.quantity" => 'Quantity must be greater than zero.']);
            }
            QuantityPolicy::assertValid($product, $line['quantity'], "items.{$i}.quantity");
        }
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
     * @param  array<array{rfq_item_id?:string,product_id:string,quantity:float,unit_price:float,discount_percent?:float,tax_percent?:float}>  $items
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

            $subtotal = BigDecimal::of('0');
            $taxTotal = BigDecimal::of('0');
            foreach ($items as $i => $line) {
                QuantityPolicy::assertValidForProductId($line['product_id'] ?? null, $line['quantity'] ?? null, "items.{$i}.quantity");
                $qty = BigDecimal::of((string) $line['quantity']);
                $unitPrice = BigDecimal::of((string) $line['unit_price']);
                $discountPercent = BigDecimal::of((string) ($line['discount_percent'] ?? 0));
                $taxPercent = BigDecimal::of((string) ($line['tax_percent'] ?? 0));

                $base = $qty->multipliedBy($unitPrice);
                $discountFactor = BigDecimal::of('1')->minus($discountPercent->dividedBy(100, 4, RoundingMode::HALF_UP));
                $afterDiscount = $base->multipliedBy($discountFactor)->toScale(4, RoundingMode::HALF_UP);
                $tax = $afterDiscount->multipliedBy($taxPercent->dividedBy(100, 4, RoundingMode::HALF_UP))->toScale(4, RoundingMode::HALF_UP);
                $lineTotal = $afterDiscount->plus($tax);

                VendorQuotationItem::query()->create([
                    'vendor_quotation_id' => $quotation->id,
                    'rfq_item_id' => $line['rfq_item_id'] ?? null,
                    'product_id' => $line['product_id'],
                    'quantity' => (string) $qty,
                    'unit_price' => (string) $unitPrice,
                    'discount_percent' => (string) $discountPercent,
                    'tax_percent' => (string) $taxPercent,
                    'line_total' => (string) $lineTotal,
                ]);

                $subtotal = $subtotal->plus($afterDiscount);
                $taxTotal = $taxTotal->plus($tax);
            }

            $freight = BigDecimal::of((string) ($attributes['freight_cost'] ?? 0))->toScale(4, RoundingMode::HALF_UP);
            $quotation->update([
                'subtotal' => (string) $subtotal,
                'tax_total' => (string) $taxTotal,
                'total' => (string) $subtotal->plus($taxTotal)->plus($freight),
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
