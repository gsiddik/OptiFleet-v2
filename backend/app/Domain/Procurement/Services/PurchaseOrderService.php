<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Services\PartnerPerformanceService;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Section 20: DRAFT -> SUBMITTED -> APPROVED -> ISSUED -> PARTIALLY_RECEIVED
 * -> RECEIVED -> CLOSED, REJECTED/CANCELLED side branches. "PO quantity and
 * price snapshot become commercial history" — items are always recalculated
 * server-side from unit_price/discount/tax at creation and never touched
 * again once issued, even if the product's catalog price later changes.
 */
class PurchaseOrderService
{
    private const RESOURCE_TYPE = 'purchase_order';

    public function __construct(
        private readonly DocumentNumberingService $numbers,
        private readonly PartnerPerformanceService $performance,
        private readonly WorkflowEngine $workflow,
    ) {}

    /**
     * Section 51/52: guards against two concurrent requests both converting
     * the same quotation — the row lock serializes them, the partial unique
     * index on purchase_orders.vendor_quotation_id is the actual backstop if
     * they land in overlapping transactions anyway.
     */
    public function createFromQuotation(VendorQuotation $quotation, Warehouse $deliveryWarehouse, array $attributes, ?string $userId): PurchaseOrder
    {
        try {
            return DB::transaction(function () use ($quotation, $deliveryWarehouse, $attributes, $userId) {
                $locked = VendorQuotation::query()->lockForUpdate()->findOrFail($quotation->id);
                if ($locked->status !== 'SELECTED') {
                    throw new ProcurementException('Only a selected quotation can be converted to a Purchase Order.');
                }
                if (PurchaseOrder::query()->where('vendor_quotation_id', $locked->id)->exists()) {
                    throw new ProcurementException('This quotation has already been converted to a Purchase Order.');
                }

                $items = $locked->items()->get()->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'quantity_ordered' => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'discount_percent' => $i->discount_percent,
                    'tax_percent' => $i->tax_percent,
                ])->all();

                return $this->create($locked->partner, $deliveryWarehouse, array_merge($attributes, [
                    'purchase_request_id' => $locked->rfq->purchase_request_id,
                    'vendor_quotation_id' => $locked->id,
                    'freight_cost' => $attributes['freight_cost'] ?? $locked->freight_cost,
                ]), $items, $userId);
            });
        } catch (QueryException $e) {
            throw new ProcurementException('This quotation has already been converted to a Purchase Order.');
        }
    }

    public function create(Partner $partner, Warehouse $deliveryWarehouse, array $attributes, array $items, ?string $userId): PurchaseOrder
    {
        if (empty($items)) {
            throw new ProcurementException('A purchase order needs at least one item.');
        }

        return DB::transaction(function () use ($partner, $deliveryWarehouse, $attributes, $items, $userId) {
            $number = $this->numbers->generate('purchase_order', $partner->tenant_id, null, null, $deliveryWarehouse->id);
            $workflowVersion = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $partner->tenant_id, null, null);

            $po = PurchaseOrder::query()->create(array_merge($attributes, [
                'tenant_id' => $partner->tenant_id,
                'po_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'workflow_configuration_version_id' => $workflowVersion?->id,
                'partner_id' => $partner->id,
                'delivery_warehouse_id' => $deliveryWarehouse->id,
                'status' => 'DRAFT',
                'created_by' => $userId,
            ]));

            $subtotal = 0.0;
            $taxTotal = 0.0;
            foreach ($items as $line) {
                $qty = (float) $line['quantity_ordered'];
                $unitPrice = (float) $line['unit_price'];
                $discountPercent = (float) ($line['discount_percent'] ?? 0);
                $taxPercent = (float) ($line['tax_percent'] ?? 0);

                $base = $qty * $unitPrice;
                $afterDiscount = $base * (1 - $discountPercent / 100);
                $tax = $afterDiscount * ($taxPercent / 100);
                $lineTotal = round($afterDiscount + $tax, 4);

                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $line['product_id'],
                    'quantity_ordered' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discountPercent,
                    'tax_percent' => $taxPercent,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $afterDiscount;
                $taxTotal += $tax;
            }

            $freight = (float) ($attributes['freight_cost'] ?? 0);
            $po->update([
                'subtotal' => round($subtotal, 4),
                'tax_total' => round($taxTotal, 4),
                'freight_cost' => $freight,
                'total' => round($subtotal + $taxTotal + $freight, 4),
            ]);

            return $po->fresh('items');
        });
    }

    public function transition(PurchaseOrder $po, string $to): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $to) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);

            $version = $this->workflow->resolvePinnedOrEffective($locked->workflow_configuration_version_id, self::RESOURCE_TYPE, $locked->tenant_id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $locked->status, $to)) {
                throw new ProcurementException("Cannot transition Purchase Order from {$locked->status} to {$to}.");
            }

            $locked->update(array_merge(['status' => $to], $to === 'ISSUED' ? ['order_date' => $locked->order_date ?? now()->toDateString()] : []));

            if ($to === 'ISSUED') {
                $partner = Partner::query()->find($locked->partner_id);
                if ($partner) {
                    $this->performance->record($partner, 'PO_ISSUED', PurchaseOrder::class, $locked->id, null, (float) $locked->total);
                }
            }

            return $locked->fresh();
        });
    }

    public function approve(PurchaseOrder $po, ?string $userId): PurchaseOrder
    {
        $updated = $this->transition($po, 'APPROVED');
        $updated->update(['approved_by' => $userId]);

        return $updated->fresh();
    }
}
