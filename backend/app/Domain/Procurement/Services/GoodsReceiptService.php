<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Services\PartnerPerformanceService;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\GoodsReceiptItem;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use App\Domain\Shared\Services\PrivateDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Section 21/22: receiving a Goods Receipt is one atomic transaction —
 * create the receipt, write the stock movement, bump warehouse stock,
 * update the PO's received quantity and status — or none of it happens.
 * Section 22: over-receipt beyond the PO's remaining quantity is rejected
 * outright by default (no tolerance in Phase 4); only the accepted quantity
 * ever reaches warehouse stock, rejected/damaged quantities never do.
 *
 * The vendor invoice the goods were received against is recorded in the same transaction
 * (new invoice + its PDF, or the invoice of an earlier receipt of this PO): receipt, stock,
 * PO status and invoice link are all saved, or none of them — and a stored PDF is removed
 * again if the transaction fails.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbers,
        private readonly PartnerPerformanceService $performance,
        private readonly VendorInvoiceReferenceService $invoices,
        private readonly PrivateDocumentStorage $storage,
    ) {}

    /**
     * @param array<array{purchase_order_item_id:string, quantity_accepted:float, quantity_rejected?:float, quantity_damaged?:float, batch_number?:string, serial_numbers?:array}> $lines
     * @param ?array{mode: string, vendor_invoice_reference_id?: ?string, vendor_invoice_number?: ?string, vendor_invoice_date?: ?string, amount?: ?string, terms_of_payment_days?: int|string|null, document?: ?UploadedFile} $invoice
     *        null only for internal callers (seeders / smoke tests); the API always sends one.
     */
    public function post(PurchaseOrder $po, Warehouse $warehouse, array $lines, ?string $userId, ?string $notes = null, ?array $invoice = null): GoodsReceipt
    {
        if (! in_array($po->status, ['ISSUED', 'PARTIALLY_RECEIVED'], true)) {
            throw new ProcurementException('Only an issued (or partially received) Purchase Order can receive goods.');
        }
        if (empty($lines)) {
            throw new ProcurementException('A goods receipt needs at least one line.');
        }

        $document = $invoice !== null && $invoice['mode'] === 'NEW' ? ($invoice['document'] ?? null) : null;
        $upload = [
            'file' => $document,
            'directory' => "vendor-invoices/{$po->tenant_id}",
            'mimes' => VendorInvoiceReferenceService::DOCUMENT_MIMES,
            'max_bytes' => VendorInvoiceReferenceService::DOCUMENT_MAX_BYTES,
            'label' => 'invoice document',
        ];

        return $this->storage->persist(['invoice_document' => $upload], fn (array $stored) => DB::transaction(function () use ($po, $warehouse, $lines, $userId, $notes, $invoice, $stored) {
            $lockedPo = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);

            $number = $this->numbers->generate('goods_receipt', $lockedPo->tenant_id, null, null, $warehouse->id);

            $receipt = GoodsReceipt::query()->create([
                'tenant_id' => $lockedPo->tenant_id,
                'gr_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'purchase_order_id' => $lockedPo->id,
                'warehouse_id' => $warehouse->id,
                'partner_id' => $lockedPo->partner_id,
                'status' => 'DRAFT',
                'received_by' => $userId,
                'received_at' => now(),
                'notes' => $notes,
            ]);

            $totalRejected = 0.0;
            $totalAccepted = 0.0;

            foreach ($lines as $line) {
                $poItem = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($line['purchase_order_item_id']);
                abort_unless($poItem->purchase_order_id === $lockedPo->id, 404);

                $accepted = (float) $line['quantity_accepted'];
                $rejected = (float) ($line['quantity_rejected'] ?? 0);
                $damaged = (float) ($line['quantity_damaged'] ?? 0);
                $remaining = $poItem->remainingQuantity();
                foreach (['quantity_accepted' => $accepted, 'quantity_rejected' => $rejected, 'quantity_damaged' => $damaged] as $field => $value) {
                    QuantityPolicy::assertValidForProductId($poItem->product_id, $value, $field);
                }

                // Section 22: over-receipt beyond the PO's remaining quantity is rejected by default.
                if (($accepted + $rejected + $damaged) > $remaining + 0.0001) {
                    throw new ProcurementException("Receiving {$accepted} (+{$rejected} rejected +{$damaged} damaged) for product {$poItem->product_id} exceeds the PO's remaining quantity of {$remaining}.");
                }

                GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $poItem->product_id,
                    'quantity_accepted' => $accepted,
                    'quantity_rejected' => $rejected,
                    'quantity_damaged' => $damaged,
                    'batch_number' => $line['batch_number'] ?? null,
                    'serial_numbers' => $line['serial_numbers'] ?? null,
                    'unit_cost' => $poItem->unit_price,
                ]);

                if ($accepted > 0) {
                    $product = Product::query()->findOrFail($poItem->product_id);
                    $this->inventory->receive($warehouse, $product, $accepted, (float) $poItem->unit_price, 'RECEIPT', GoodsReceipt::class, $receipt->id, $userId);
                    $poItem->increment('quantity_received', $accepted);
                }

                $totalAccepted += $accepted;
                $totalRejected += $rejected + $damaged;
            }

            $receipt->update(['status' => 'POSTED']);

            if ($invoice !== null) {
                $reference = $this->invoices->resolveForReceipt($lockedPo, $receipt, $invoice, $stored['invoice_document'], $userId);
                $receipt->update(['vendor_invoice_reference_id' => $reference->id]);
            }

            $stillOpen = $lockedPo->items()->get()->contains(fn (PurchaseOrderItem $i) => $i->remainingQuantity() > 0.0001);
            $lockedPo->update(['status' => $stillOpen ? 'PARTIALLY_RECEIVED' : 'RECEIVED']);

            $partner = Partner::query()->find($lockedPo->partner_id);
            if ($partner) {
                if ($totalAccepted > 0) {
                    $this->performance->record($partner, 'GOODS_ACCEPTED', GoodsReceipt::class, $receipt->id, $totalAccepted);
                }
                if ($totalRejected > 0) {
                    $this->performance->record($partner, 'GOODS_REJECTED', GoodsReceipt::class, $receipt->id, $totalRejected);
                }
                $onTime = ! $lockedPo->expected_delivery_date || now()->toDateString() <= $lockedPo->expected_delivery_date->toDateString();
                $this->performance->record($partner, $onTime ? 'DELIVERY_ON_TIME' : 'DELIVERY_LATE', GoodsReceipt::class, $receipt->id);
            }

            return $receipt->fresh(['items', 'vendorInvoiceReference']);
        }));
    }
}
