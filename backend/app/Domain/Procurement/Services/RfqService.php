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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Only ACTIVE partners of the RFQ's tenant whose type supplies goods (Supplier, Spare Part
     * Supplier, Tire Supplier) can be invited, and only while the RFQ is still open.
     */
    public function inviteVendors(Rfq $rfq, array $partnerIds): Rfq
    {
        if (in_array($rfq->status, ['CLOSED', 'CANCELLED'], true)) {
            throw new ProcurementException("Vendors cannot be invited to an RFQ that is {$rfq->status}.");
        }
        foreach (array_values($partnerIds) as $i => $partnerId) {
            $partner = Partner::query()->withoutGlobalScopes()->find($partnerId);
            if (! $partner || $partner->tenant_id !== $rfq->tenant_id || $partner->status !== 'ACTIVE' || ! in_array($partner->partner_type, Partner::RFQ_VENDOR_TYPES, true)) {
                throw ValidationException::withMessages(["partner_ids.{$i}" => 'Only an active Supplier, Spare Part Supplier or Tire Supplier can be invited.']);
            }
        }

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
     * Record Quotation. Business rules (owner decision 4): the RFQ must be ISSUED, the vendor must
     * be invited, a vendor records ONE quotation per RFQ (a vendor already in Quotation Comparison
     * can never be entered again) and every line must be an item of this RFQ. The vendor's
     * quotation document is optional; when supplied it is validated and stored privately. Totals
     * are always recalculated here, never taken from the client.
     *
     * @param  array<array{rfq_item_id?:string,product_id:string,quantity:float,unit_price:float,discount_percent?:float,tax_percent?:float}>  $items
     */
    public function submitQuotation(Rfq $rfq, Partner $partner, array $attributes, array $items, ?UploadedFile $attachment = null, ?string $userId = null): VendorQuotation
    {
        if (empty($items)) {
            throw new ProcurementException('A quotation needs at least one item.');
        }
        if ($rfq->status !== 'ISSUED') {
            throw new ProcurementException('Quotations can only be recorded while the RFQ is issued.');
        }
        if (! $rfq->vendors()->whereKey($partner->id)->exists()) {
            throw ValidationException::withMessages(['partner_id' => 'Only a vendor invited to this RFQ can submit a quotation.']);
        }
        if (VendorQuotation::query()->where('rfq_id', $rfq->id)->where('partner_id', $partner->id)->exists()) {
            throw ValidationException::withMessages(['partner_id' => "{$partner->name} has already submitted a quotation for this RFQ."]);
        }
        $rfqItems = $rfq->items()->get()->keyBy('id');
        $quoted = [];
        foreach (array_values($items) as $i => $line) {
            $rfqItem = isset($line['rfq_item_id']) ? $rfqItems->get($line['rfq_item_id']) : $rfqItems->firstWhere('product_id', $line['product_id']);
            if (! $rfqItem || $rfqItem->product_id !== $line['product_id'] || isset($quoted[$rfqItem->id])) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => 'Each quotation line must be a distinct item of this RFQ.']);
            }
            $quoted[$rfqItem->id] = true;
        }

        if (! $attachment) {
            return $this->recordQuotation($rfq, $partner, $attributes, $items);
        }

        $stored = app(QuotationAttachmentService::class)->store($attachment, $rfq->tenant_id, $userId);

        try {
            return $this->recordQuotation($rfq, $partner, array_merge($attributes, $stored), $items);
        } catch (\Throwable $e) {
            Storage::disk($stored['attachment_disk'])->delete($stored['attachment_path']);
            throw $e;
        }
    }

    private function recordQuotation(Rfq $rfq, Partner $partner, array $attributes, array $items): VendorQuotation
    {
        return DB::transaction(function () use ($rfq, $partner, $attributes, $items) {
            Rfq::query()->lockForUpdate()->findOrFail($rfq->id);
            $quotation = VendorQuotation::query()->create(array_merge(
                array_intersect_key($attributes, array_flip((new VendorQuotation)->getFillable())),
                [
                    'tenant_id' => $rfq->tenant_id,
                    'rfq_id' => $rfq->id,
                    'partner_id' => $partner->id,
                    'status' => 'SUBMITTED',
                    'submitted_at' => now(),
                ],
            ));

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
            'submitted_at' => optional($q->submitted_at)->toDateTimeString(),
            'has_attachment' => $q->has_attachment,
            'attachment_original_filename' => $q->attachment_original_filename,
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
