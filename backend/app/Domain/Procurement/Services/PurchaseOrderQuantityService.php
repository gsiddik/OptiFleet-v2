<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\PurchaseReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order line quantity accounting — the single source of truth for what can still be
 * received (owner rule):
 *
 *  - every Goods Receipt (original delivery or redelivered replacement) reduces Remaining;
 *  - every Return to Vendor reduces Remaining once, at the physical return;
 *  - a Redelivery Request — or a Refund Request the vendor rejects — re-opens that returned quantity
 *    exactly once; the replacement is then received through Goods Receipt;
 *  - an accepted refund changes nothing more (the return already removed the quantity).
 *
 *     Remaining = Ordered − Received − Returned + Reopened
 *
 * With the stored aggregates (quantity_returned = every return; quantity_refunded = returns whose
 * refund is requested or accepted, released again when the vendor rejects) Reopened = Returned −
 * Refunded, so Remaining = Ordered − Received − Refunded. Example: 24 ordered, 18 received
 * (including 2 + 5 redelivered replacements), returns 2 + 5 (redelivery) + 5 (refund accepted) →
 * 24 − 18 − 12 + 7 = 1.
 */
class PurchaseOrderQuantityService
{
    /** Remaining Receivable Qty of one line (may be checked against Goods Receipt input). */
    public function remaining(PurchaseOrderItem $item): BigDecimal
    {
        return $this->dec($item->quantity_ordered)->minus($this->dec($item->quantity_received))->minus($this->dec($item->quantity_refunded));
    }

    /**
     * Every quantity of every line of a PO, as decimal strings (4 dp), keyed by line id.
     *
     * @return array<string, array<string, string>>
     */
    public function forPurchaseOrder(PurchaseOrder $po): array
    {
        $po->loadMissing('items');
        // Refund quantities by state, straight from the Return Orders (one query for the PO).
        $refunds = DB::table('purchase_return_items as ri')->join('purchase_returns as r', 'r.id', '=', 'ri.purchase_return_id')
            ->where('r.purchase_order_id', $po->id)->where('r.return_option', PurchaseReturn::REFUND)
            ->groupBy('ri.purchase_order_item_id', 'r.status')
            ->select(['ri.purchase_order_item_id', 'r.status', DB::raw('SUM(ri.quantity) as quantity')])->get()
            ->groupBy('purchase_order_item_id');

        return $po->items->mapWithKeys(function (PurchaseOrderItem $item) use ($refunds) {
            $byStatus = ($refunds[$item->id] ?? collect())->pluck('quantity', 'status');
            $returned = $this->dec($item->quantity_returned);
            $refunded = $this->dec($item->quantity_refunded);
            $remaining = $this->remaining($item);

            return [$item->id => array_map(fn (BigDecimal $v) => (string) $v->toScale(4, RoundingMode::HALF_UP), [
                'ordered_quantity' => $this->dec($item->quantity_ordered),
                'gross_received_quantity' => $this->dec($item->quantity_received),
                'returned_quantity' => $returned,
                // Returned quantity re-opened for the vendor's redelivery (Redelivery Requests + rejected refunds).
                'reopened_for_redelivery_quantity' => $returned->minus($refunded),
                'refund_requested_quantity' => $this->dec($byStatus[PurchaseReturn::REFUND_REQUESTED] ?? 0),
                'accepted_refund_quantity' => $this->dec($byStatus[PurchaseReturn::REFUND_ACCEPTED] ?? 0),
                // Goods physically kept from this line.
                'net_held_quantity' => $this->dec($item->quantity_received)->minus($returned),
                'remaining_receivable_quantity' => $remaining->isNegative() ? BigDecimal::zero() : $remaining,
            ])];
        })->all();
    }

    private function dec(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0));
    }
}
