<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Services\PartnerPerformanceService;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use App\Domain\Workflow\Services\WorkflowApprovalService;
use App\Domain\Workflow\Services\WorkflowEngine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
        private readonly WorkflowApprovalService $approvals,
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

            $subtotal = BigDecimal::of('0');
            $taxTotal = BigDecimal::of('0');
            foreach ($items as $line) {
                $qty = BigDecimal::of((string) $line['quantity_ordered']);
                $unitPrice = BigDecimal::of((string) $line['unit_price']);
                $discountPercent = BigDecimal::of((string) ($line['discount_percent'] ?? 0));
                $taxPercent = BigDecimal::of((string) ($line['tax_percent'] ?? 0));

                $base = $qty->multipliedBy($unitPrice);
                $discountFactor = BigDecimal::of('1')->minus($discountPercent->dividedBy(100, 4, RoundingMode::HALF_UP));
                $afterDiscount = $base->multipliedBy($discountFactor)->toScale(4, RoundingMode::HALF_UP);
                $tax = $afterDiscount->multipliedBy($taxPercent->dividedBy(100, 4, RoundingMode::HALF_UP))->toScale(4, RoundingMode::HALF_UP);
                $lineTotal = $afterDiscount->plus($tax);

                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $line['product_id'],
                    'quantity_ordered' => (string) $qty,
                    'unit_price' => (string) $unitPrice,
                    'discount_percent' => (string) $discountPercent,
                    'tax_percent' => (string) $taxPercent,
                    'line_total' => (string) $lineTotal,
                ]);

                $subtotal = $subtotal->plus($afterDiscount);
                $taxTotal = $taxTotal->plus($tax);
            }

            $freight = BigDecimal::of((string) ($attributes['freight_cost'] ?? 0))->toScale(4, RoundingMode::HALF_UP);
            $po->update([
                'subtotal' => (string) $subtotal,
                'tax_total' => (string) $taxTotal,
                'freight_cost' => (string) $freight,
                'total' => (string) $subtotal->plus($taxTotal)->plus($freight),
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

    /**
     * G-06: single-tier by default (matches the pre-existing behavior
     * exactly whenever no tenant has published a purchase_order workflow
     * configuration with an approval_rule on its 'approved' transition).
     * A tenant that publishes one — via the existing Configuration
     * versioning/publish endpoints, condition_set-gated on this PO's own
     * `total` — gets real multi-step tiered approval through the same
     * generic engine every other maker-checker flow in this codebase uses.
     * No thresholds, tier counts, or approver roles are defined here.
     */
    public function approve(PurchaseOrder $po, ?string $userId): PurchaseOrder
    {
        $version = $this->workflow->resolvePinnedOrEffective($po->workflow_configuration_version_id, self::RESOURCE_TYPE, $po->tenant_id);
        $transition = $version ? $this->workflow->findTransition($version, $po->status, 'approved') : null;
        $approvalRule = $transition['approval_rule'] ?? null;

        if (! $approvalRule) {
            $updated = $this->transition($po, 'APPROVED');
            $updated->update(['approved_by' => $userId]);

            return $updated->fresh();
        }

        return DB::transaction(function () use ($po, $userId, $version, $approvalRule) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $locked->status, 'APPROVED')) {
                throw new ProcurementException("Cannot transition Purchase Order from {$locked->status} to APPROVED.");
            }

            $request = $this->approvals->createRequest(
                $locked->tenant_id, self::RESOURCE_TYPE, $locked->id, $version->id,
                'approved', $locked->status, 'APPROVED', $approvalRule,
                ['total' => (float) $locked->total], $userId,
            );

            $locked->update(['status' => 'PENDING_APPROVAL', 'workflow_approval_request_id' => $request->id]);

            if ($this->approvals->isFullyApproved($request)) {
                $locked->update(['status' => 'APPROVED', 'approved_by' => $userId]);
            }

            return $locked->fresh();
        });
    }

    /** G-06: decides the next pending step of a PO's in-flight tiered approval. */
    public function decideApproval(PurchaseOrder $po, string $decision, string $userId, ?string $note = null): PurchaseOrder
    {
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw new ProcurementException("Invalid decision '{$decision}' — must be APPROVED or REJECTED.");
        }

        return DB::transaction(function () use ($po, $decision, $userId, $note) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            if ($locked->status !== 'PENDING_APPROVAL') {
                throw new ProcurementException("Cannot decide an approval for a Purchase Order that is {$locked->status} (must be PENDING_APPROVAL).");
            }

            $request = WorkflowApprovalRequest::query()->findOrFail($locked->workflow_approval_request_id);
            // Maker-checker: the actor who triggered approve() cannot also decide the resulting steps
            // (WorkflowApprovalService itself only checks per-step eligibility, not requester identity).
            if ($userId === $request->requested_by) {
                throw new ProcurementException('The user who submitted this Purchase Order for approval cannot also decide its approval steps.');
            }
            $step = $request->steps()->where('status', 'PENDING')->orderBy('step_number')->firstOrFail();
            $this->approvals->decide($step, $decision, $userId, $note);

            if ($decision === 'REJECTED') {
                $locked->update(['status' => 'REJECTED']);

                return $locked->fresh();
            }

            if ($this->approvals->isFullyApproved($request)) {
                $locked->update(['status' => 'APPROVED', 'approved_by' => $userId]);
            }

            return $locked->fresh();
        });
    }
}
