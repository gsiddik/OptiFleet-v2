<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use App\Domain\Workflow\Services\WorkflowApprovalService;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\WorkOrder\Models\SparePartSale;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use Illuminate\Support\Facades\DB;

/**
 * G-16: Sell Sparepart. A sale can only be created against a
 * work_order_part_returns row that Phase B finalized as SELL_ELIGIBLE —
 * "unprocessed items cannot be sold" is enforced structurally, not just
 * by convention. The eligible quantity is a shared, contested resource
 * (several sale lines can draw against the same return), so create()
 * locks the return row before summing existing active sales against it
 * — the same TOCTOU fix pattern as G-18's returnPart().
 */
class SparePartSaleService
{
    private const RESOURCE_TYPE = 'sparepart_sale';

    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly WorkflowApprovalService $approvals,
        private readonly InventoryService $inventory,
    ) {}

    public function create(WorkOrderPartReturn $return, array $data, string $userId): SparePartSale
    {
        $saleType = $data['sale_type'];
        $buyerType = $data['buyer_type'];
        $quantity = (float) $data['quantity'];
        $unitPrice = (float) $data['unit_price'];

        if (! in_array($saleType, SparePartSale::SALE_TYPES, true)) {
            throw new WorkOrderException('Sale type must be one of: '.implode(', ', SparePartSale::SALE_TYPES).'.');
        }
        if (! in_array($buyerType, SparePartSale::BUYER_TYPES, true)) {
            throw new WorkOrderException('Buyer type must be one of: '.implode(', ', SparePartSale::BUYER_TYPES).'.');
        }
        if ($buyerType === 'PARTNER' && empty($data['partner_id'])) {
            throw new WorkOrderException('A PARTNER sale requires partner_id.');
        }
        if ($buyerType === 'EXTERNAL' && empty($data['buyer_name'])) {
            throw new WorkOrderException('An EXTERNAL sale requires buyer_name.');
        }
        if ($quantity <= 0) {
            throw new WorkOrderException('Sale quantity must be positive.');
        }
        if ($unitPrice < 0) {
            throw new WorkOrderException('Unit price cannot be negative.');
        }

        return DB::transaction(function () use ($return, $saleType, $buyerType, $quantity, $unitPrice, $data, $userId) {
            $locked = WorkOrderPartReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->disposition_status !== 'FINALIZED' || $locked->disposition !== 'SELL_ELIGIBLE') {
                throw new WorkOrderException('Only a return finalized with disposition SELL_ELIGIBLE can be sold.');
            }
            // Defense in depth: Phase B already blocks USED_FAULTY from ever reaching
            // SELL_ELIGIBLE, but a sale explicitly labelled for operational reuse must
            // never be able to represent an unsafe item, even if that upstream gate
            // were ever weakened or bypassed by a direct write.
            if ($saleType === 'OPERATIONAL_REUSE' && $locked->condition === 'USED_FAULTY') {
                throw new WorkOrderException('A USED_FAULTY-sourced item cannot be sold as OPERATIONAL_REUSE.');
            }
            if ($quantity > $locked->remainingEligibleQuantity()) {
                throw new WorkOrderException("Cannot sell more than the remaining eligible quantity ({$locked->remainingEligibleQuantity()}).");
            }

            return SparePartSale::query()->create([
                'tenant_id' => $locked->tenant_id,
                'work_order_part_return_id' => $locked->id,
                'product_id' => $locked->product_id,
                'warehouse_id' => $locked->warehouse_id,
                'quantity' => $quantity,
                'sale_type' => $saleType,
                'buyer_type' => $buyerType,
                'partner_id' => $data['partner_id'] ?? null,
                'buyer_name' => $data['buyer_name'] ?? null,
                'unit_price' => $unitPrice,
                'total_amount' => round($quantity * $unitPrice, 4),
                'status' => 'DRAFT',
                'requested_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function submit(SparePartSale $sale, string $userId): SparePartSale
    {
        return DB::transaction(function () use ($sale, $userId) {
            $locked = SparePartSale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== 'DRAFT') {
                throw new WorkOrderException("Cannot submit a sale that is {$locked->status} (must be DRAFT).");
            }

            $version = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $locked->tenant_id);
            if (! $version) {
                throw new WorkOrderException('No published sparepart-sale workflow configuration is available for this tenant.');
            }

            $request = $this->approvals->createRequest(
                $locked->tenant_id,
                self::RESOURCE_TYPE,
                $locked->id,
                $version->id,
                'approve',
                'PENDING_APPROVAL',
                'APPROVED',
                ['type' => 'SINGLE', 'steps' => [
                    ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'sparepart_sale.approve'],
                ]],
                ['sale_type' => $locked->sale_type, 'total_amount' => (float) $locked->total_amount],
                $userId,
            );

            $locked->update([
                'status' => 'PENDING_APPROVAL',
                'requested_by' => $userId,
                'workflow_configuration_version_id' => $version->id,
                'workflow_approval_request_id' => $request->id,
            ]);

            return $locked->fresh();
        });
    }

    public function decide(SparePartSale $sale, string $decision, string $userId, ?string $note = null): SparePartSale
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new WorkOrderException("Invalid decision '{$decision}' — must be APPROVE or REJECT.");
        }

        return DB::transaction(function () use ($sale, $decision, $userId, $note) {
            $locked = SparePartSale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== 'PENDING_APPROVAL') {
                throw new WorkOrderException("Cannot decide a sale that is {$locked->status} (must be PENDING_APPROVAL).");
            }
            if ($userId === $locked->requested_by) {
                throw new WorkOrderException('The maker who submitted this sale cannot also approve or reject it.');
            }

            $request = WorkflowApprovalRequest::query()->findOrFail($locked->workflow_approval_request_id);
            $step = $request->steps()->where('status', 'PENDING')->orderBy('step_number')->firstOrFail();
            $this->approvals->decide($step, $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED', $userId, $note);

            if ($decision === 'REJECT') {
                $locked->update(['status' => 'REJECTED', 'decided_by' => $userId, 'decided_at' => now(), 'rejection_reason' => $note]);

                return $locked->fresh();
            }

            // The sale never touched quantity_on_hand (the return it draws from never
            // did either) — this SALE entry is a zero-balance-effect audit/history
            // marker, exactly like Phase A's CONSUME movement.
            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
            $product = Product::query()->findOrFail($locked->product_id);
            $movement = $this->inventory->recordSale($warehouse, $product, (float) $locked->quantity, SparePartSale::class, $locked->id, $userId, 'Sparepart sold');

            $locked->update([
                'status' => 'APPROVED', 'decided_by' => $userId, 'decided_at' => now(), 'stock_movement_id' => $movement->id,
            ]);

            return $locked->fresh();
        });
    }
}
