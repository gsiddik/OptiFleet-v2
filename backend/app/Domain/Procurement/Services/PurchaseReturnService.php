<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\PurchaseReturn;
use App\Domain\Procurement\Models\PurchaseReturnEvent;
use App\Domain\Procurement\Models\PurchaseReturnItem;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order Return to Vendor. Every transition locks the Purchase Order (or the Return
 * Order) and runs in one transaction — no partial state:
 *
 *   PARTIALLY_RECEIVED PO ──create──▶ REFUND_REQUESTED ──accept──▶ REFUND_ACCEPTED
 *                                                       ──reject──▶ REDELIVERY_PENDING ──receive──▶ REDELIVERY_RECEIVED
 *                                     REDELIVERY_REQUESTED ──print──▶ REDELIVERY_READY ──receive──▶ REDELIVERY_RECEIVED
 *
 * Creating a Return Order takes the returned quantity out of stock (RETURN_TO_VENDOR) and
 * records it per PO line (quantity_returned; a refund also quantity_refunded). Quantity accounting
 * lives in PurchaseOrderQuantityService: the return reduces Remaining once; a Redelivery Request (or
 * a rejected refund) re-opens it once; an accepted refund changes nothing more. Goods Receipt is
 * blocked while a redelivery is awaited and re-opens once "Receive Redelivery" is recorded; the
 * redelivered goods are then received — and counted — by a normal Goods Receipt only.
 */
class PurchaseReturnService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbers,
    ) {}

    /** Per PO line quantities and what the PO detail may offer (the UI follows these flags). */
    public function summary(PurchaseOrder $po): array
    {
        $po->loadMissing('items');
        $open = $this->openReturn($po->id);

        return [
            'can_return' => $po->status === 'PARTIALLY_RECEIVED' && $open === null && $po->items->contains(fn (PurchaseOrderItem $i) => $i->returnableQuantity() > 0.0001),
            'open_return_id' => $open?->id,
            'goods_receipt_blocked' => $open !== null && in_array($open->status, PurchaseReturn::AWAITING_REDELIVERY, true),
            // Backend-calculated per line (PurchaseOrderQuantityService); remaining_quantity is kept for
            // compatibility and equals remaining_receivable_quantity.
            'items' => collect(app(PurchaseOrderQuantityService::class)->forPurchaseOrder($po))->map(fn (array $q, string $id) => $q + [
                'returnable_quantity' => $this->qty($po->items->firstWhere('id', $id)->returnableQuantity()),
                'remaining_quantity' => $this->qty((float) $q['remaining_receivable_quantity']),
            ])->all(),
        ];
    }

    /**
     * @param  array<int, array{purchase_order_item_id: string, quantity: string|float}>  $lines
     */
    public function create(PurchaseOrder $po, string $option, array $lines, ?string $notes, string $userId): PurchaseReturn
    {
        if (! in_array($option, PurchaseReturn::OPTIONS, true)) {
            throw new ProcurementException('Return Option must be Refund Request or Redelivery Request.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) !== 0.0));
        if ($lines === []) {
            throw new ProcurementException('Enter the quantity returned to the vendor for at least one item.');
        }

        try {
            return DB::transaction(function () use ($po, $option, $lines, $notes, $userId) {
                $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
                if ($locked->status !== 'PARTIALLY_RECEIVED') {
                    throw new ProcurementException("Only a partially received Purchase Order can return goods to the vendor (this one is {$locked->status}).");
                }
                if ($this->openReturn($locked->id)) {
                    throw new ProcurementException('This Purchase Order already has an open Return Order — finish it first.');
                }
                $warehouse = Warehouse::query()->findOrFail($locked->delivery_warehouse_id);
                $number = $this->numbers->generate('purchase_return', $locked->tenant_id, null, null, $warehouse->id);
                $status = $option === PurchaseReturn::REFUND ? PurchaseReturn::REFUND_REQUESTED : PurchaseReturn::REDELIVERY_REQUESTED;
                $return = PurchaseReturn::query()->create([
                    'tenant_id' => $locked->tenant_id, 'return_number' => $number['document_number'],
                    'numbering_configuration_version_id' => $number['configuration_version_id'],
                    'purchase_order_id' => $locked->id, 'partner_id' => $locked->partner_id, 'warehouse_id' => $warehouse->id,
                    'return_option' => $option, 'status' => $status, 'returned_at' => now(), 'notes' => $notes, 'created_by' => $userId,
                ]);

                $seen = [];
                foreach ($lines as $i => $line) {
                    $item = PurchaseOrderItem::query()->lockForUpdate()->find($line['purchase_order_item_id'] ?? null);
                    if (! $item || $item->purchase_order_id !== $locked->id) {
                        throw new ProcurementException('Each returned line must be an item of this Purchase Order.');
                    }
                    if (isset($seen[$item->id])) {
                        throw new ProcurementException('Each Purchase Order item can appear only once on a Return Order.');
                    }
                    $seen[$item->id] = true;
                    $quantity = (float) $line['quantity'];
                    QuantityPolicy::assertValidForProductId($item->product_id, $line['quantity'], "items.{$i}.quantity");
                    if ($quantity <= 0) {
                        throw new ProcurementException('Qty Returned to Vendor must be greater than zero.');
                    }
                    $returnable = $item->returnableQuantity();
                    if ($quantity > $returnable + 0.0001) {
                        throw new ProcurementException("Qty Returned to Vendor ({$this->qty($quantity)}) exceeds the received quantity still held for this item ({$this->qty($returnable)}).");
                    }
                    // A return for refund reduces Remaining and is not re-opened: it can never take Remaining below zero.
                    $remaining = $item->remainingQuantity();
                    if ($option === PurchaseReturn::REFUND && $quantity > $remaining + 0.0001) {
                        throw new ProcurementException("A Refund Request can cover at most the remaining quantity of this item ({$this->qty(max(0, $remaining))}); return the rest as a Redelivery Request.");
                    }

                    $product = Product::query()->withTrashed()->findOrFail($item->product_id);
                    try {
                        $this->inventory->returnToVendor($warehouse, $product, $quantity, PurchaseReturn::class, $return->id, $userId, "Return Order {$return->return_number}");
                    } catch (InventoryException $e) {
                        throw new ProcurementException("{$product->name}: {$e->getMessage()}");
                    }
                    PurchaseReturnItem::query()->create([
                        'purchase_return_id' => $return->id, 'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $quantity,
                    ]);
                    $item->increment('quantity_returned', $quantity);
                    if ($option === PurchaseReturn::REFUND) {
                        $item->increment('quantity_refunded', $quantity);
                    }
                }

                $this->event($return, null, $status, 'CREATED', $notes, $userId);

                return $return->fresh(['items.product', 'events']);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ProcurementException('This Purchase Order already has an open Return Order — finish it first.');
        }
    }

    /** Print Return Order: a printed redelivery request is ready for "Receive Redelivery". */
    public function markPrinted(PurchaseReturn $return, string $userId): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->printed_at === null) {
                $locked->update(['printed_at' => now(), 'printed_by' => $userId]);
            }
            if ($locked->status === PurchaseReturn::REDELIVERY_REQUESTED) {
                $locked->update(['status' => PurchaseReturn::REDELIVERY_READY]);
                $this->event($locked, PurchaseReturn::REDELIVERY_REQUESTED, PurchaseReturn::REDELIVERY_READY, 'PRINTED', null, $userId);
            }

            return $locked->fresh();
        });
    }

    /** Accepted by Vendor: the refund is final and its amount is recorded per line. */
    public function accept(PurchaseReturn $return, ?string $note, string $userId): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $note, $userId) {
            $locked = $this->lockFor($return, PurchaseReturn::REFUND_REQUESTED, 'accepted');
            $total = BigDecimal::zero();
            foreach ($locked->items()->get() as $item) {
                $amount = $this->refundAmount(PurchaseOrderItem::query()->findOrFail($item->purchase_order_item_id), (string) $item->quantity);
                $item->update(['refund_amount' => (string) $amount]);
                $total = $total->plus($amount);
            }
            $locked->update([
                'status' => PurchaseReturn::REFUND_ACCEPTED, 'refunded_amount' => (string) $total, 'vendor_decision' => 'ACCEPTED',
                'vendor_decided_by' => $userId, 'vendor_decided_at' => now(), 'vendor_decision_note' => $note,
            ]);
            $this->event($locked, PurchaseReturn::REFUND_REQUESTED, PurchaseReturn::REFUND_ACCEPTED, 'VENDOR_ACCEPTED', $note, $userId);

            return $locked->fresh(['items.product', 'events']);
        });
    }

    /** Rejected by Vendor: the vendor redelivers the goods instead — the refund request stays in history. */
    public function reject(PurchaseReturn $return, ?string $note, string $userId): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $note, $userId) {
            $locked = $this->lockFor($return, PurchaseReturn::REFUND_REQUESTED, 'rejected');
            foreach ($locked->items()->get() as $item) {
                // No refund: the returned quantity is expected again from the vendor.
                PurchaseOrderItem::query()->lockForUpdate()->findOrFail($item->purchase_order_item_id)->decrement('quantity_refunded', (float) $item->quantity);
            }
            $locked->update([
                'status' => PurchaseReturn::REDELIVERY_PENDING, 'vendor_decision' => 'REJECTED',
                'vendor_decided_by' => $userId, 'vendor_decided_at' => now(), 'vendor_decision_note' => $note,
            ]);
            $this->event($locked, PurchaseReturn::REFUND_REQUESTED, 'REFUND_REJECTED', 'VENDOR_REJECTED', $note, $userId);
            $this->event($locked, 'REFUND_REJECTED', PurchaseReturn::REDELIVERY_PENDING, 'REDELIVERY_EXPECTED', 'The vendor will redeliver the goods.', $userId);

            return $locked->fresh(['items.product', 'events']);
        });
    }

    /** Receive Redelivery: Goods Receipt re-opens to receive the redelivered goods. */
    public function receiveRedelivery(PurchaseReturn $return, string $userId): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status === PurchaseReturn::REDELIVERY_REQUESTED) {
                throw new ProcurementException('Print the Return Order first — the redelivery can be received after the Return Order was printed.');
            }
            if (! in_array($locked->status, [PurchaseReturn::REDELIVERY_READY, PurchaseReturn::REDELIVERY_PENDING], true)) {
                throw new ProcurementException("This Return Order is {$locked->status}; no redelivery is awaited.");
            }
            $from = $locked->status;
            $locked->update(['status' => PurchaseReturn::REDELIVERY_RECEIVED, 'redelivery_received_by' => $userId, 'redelivery_received_at' => now()]);
            $this->event($locked, $from, PurchaseReturn::REDELIVERY_RECEIVED, 'REDELIVERY_RECEIVED', null, $userId);

            return $locked->fresh(['items.product', 'events']);
        });
    }

    /** Goods Receipt guard: no receipt while a redelivery is awaited. */
    public function assertGoodsReceiptAllowed(PurchaseOrder $po): void
    {
        $open = $this->openReturn($po->id);
        if ($open && in_array($open->status, PurchaseReturn::AWAITING_REDELIVERY, true)) {
            throw new ProcurementException("Return Order {$open->return_number} is waiting for the vendor's redelivery — record \"Receive Redelivery\" before posting a Goods Receipt.");
        }
    }

    /**
     * Refund of a PO line quantity with the line's own pricing (same formula and rounding as the
     * PO line total): qty × unit price, less the line discount, plus the line tax. Freight is a
     * PO-level charge and is not part of a line refund.
     */
    public function refundAmount(PurchaseOrderItem $item, string $quantity): BigDecimal
    {
        $base = BigDecimal::of($quantity)->multipliedBy(BigDecimal::of((string) $item->unit_price));
        $discountFactor = BigDecimal::one()->minus(BigDecimal::of((string) $item->discount_percent)->dividedBy(100, 4, RoundingMode::HALF_UP));
        $afterDiscount = $base->multipliedBy($discountFactor)->toScale(4, RoundingMode::HALF_UP);
        $tax = $afterDiscount->multipliedBy(BigDecimal::of((string) $item->tax_percent)->dividedBy(100, 4, RoundingMode::HALF_UP))->toScale(4, RoundingMode::HALF_UP);

        return $afterDiscount->plus($tax);
    }

    public function openReturn(string $purchaseOrderId): ?PurchaseReturn
    {
        return PurchaseReturn::query()->withoutGlobalScopes()->where('purchase_order_id', $purchaseOrderId)->whereIn('status', PurchaseReturn::OPEN)->first();
    }

    private function lockFor(PurchaseReturn $return, string $expected, string $verb): PurchaseReturn
    {
        $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
        if ($locked->status !== $expected) {
            throw new ProcurementException("This Return Order is {$locked->status}; only a requested refund can be {$verb} by the vendor.");
        }

        return $locked;
    }

    private function event(PurchaseReturn $return, ?string $from, string $to, string $event, ?string $note, ?string $userId): void
    {
        PurchaseReturnEvent::query()->create([
            'purchase_return_id' => $return->id, 'from_status' => $from, 'to_status' => $to, 'event' => $event,
            'note' => $note, 'performed_by' => $userId, 'occurred_at' => now(),
        ]);
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
