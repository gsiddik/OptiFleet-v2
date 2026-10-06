<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use App\Domain\Shared\Support\Messages;
use App\Domain\Tire\Services\TireOperationExecutionService;
use App\Domain\Tire\Services\UsedTireStockService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPartReturnEvidence;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Support\Facades\DB;

/**
 * Section 7/8/9/18/45: extends Phase 3's planned-parts line with the
 * Issue -> Consumption/Return lifecycle (stock is requested through Part Requests; the
 * standalone Inventory Reservation feature is retired). Every stock
 * mutation still happens inside InventoryService —
 * this class only orchestrates the WorkOrderPlannedPart bookkeeping
 * (status, quantities, cost snapshot) around those calls.
 */
class WorkOrderPartService
{
    /**
     * Every return condition the domain knows (legacy rows keep the USED_* ones); Issuance &
     * Return itself only accepts NEW_PART_CONDITIONS — see returnPart().
     */
    public const CONDITIONS = ['UNUSED_NEW', 'UNUSED_FAULTY', 'USED_GOOD', 'USED_FAULTY'];

    /** Conditions of a new part returned unused (the only ones Issuance & Return accepts). */
    public const NEW_PART_CONDITIONS = ['UNUSED_NEW', 'UNUSED_FAULTY'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly WorkOrderExecutionService $execution,
        private readonly DocumentNumberingService $numbers,
        private readonly TireOperationExecutionService $tireOperations,
        private readonly UsedTireStockService $usedStock,
    ) {}

    public function issue(WorkOrderPlannedPart $part, ?float $quantity, ?string $userId): WorkOrderPlannedPart
    {
        if (! $part->product_id || ! $part->warehouse_id) {
            throw new WorkOrderException('This planned part has no product/warehouse resolved — reserve it first.');
        }

        return DB::transaction(function () use ($part, $quantity, $userId) {
            $workOrder = WorkOrder::query()->findOrFail($part->work_order_id);
            $this->execution->assertExecutable($workOrder);
            $warehouse = Warehouse::query()->findOrFail($part->warehouse_id);
            $product = Product::query()->findOrFail($part->product_id);
            $toIssue = $quantity ?? ((float) $part->planned_quantity - (float) $part->issued_quantity);
            if ($toIssue <= 0) {
                throw new WorkOrderException('Nothing left to issue for this planned part.');
            }

            if ($part->stock_condition === 'USED') {
                $result = $this->issueUsedTires($part, $warehouse, $toIssue, $userId);
            } else {
                // Only this line's own reservation may be consumed; the rest must be unreserved stock.
                $ownReserved = min($toIssue, (float) $part->reserved_quantity);
                $result = $this->inventory->issue($warehouse, $product, $toIssue, WorkOrderPlannedPart::class, $part->id, $userId, null, $ownReserved);
            }

            $newIssuedTotal = (float) $part->issued_quantity + $toIssue;
            $newReservedTotal = max(0, (float) $part->reserved_quantity - $toIssue);
            $priorTotalCost = (float) ($part->total_cost ?? 0);

            $part->update([
                'issued_quantity' => $newIssuedTotal,
                'reserved_quantity' => $newReservedTotal,
                'unit_cost_at_issue' => $result['unit_cost'],
                'total_cost' => $priorTotalCost + $result['total_cost'],
            ]);

            $this->recomputeStatus($part->fresh());

            return $part->fresh();
        });
    }

    /**
     * New-part return from Issuance & Return (an issued part that was NOT used). Only the
     * never-installed conditions are accepted here; a component that was installed and later
     * taken off the vehicle is recorded through Removed Components (Used Sparepart Processing).
     *
     * The return creates a NEW_PART record (server-generated Return Number) in
     * PENDING_PROCESSING and posts NO stock: the part only becomes available again once
     * Returned Parts Processing inspects and accepts it (ReturnProcessingService). The
     * planned-part row is locked inside the transaction that checks the returnable quantity,
     * so concurrent returns serialize instead of over-returning.
     *
     * @param  array<string>  $evidenceIds  Not-yet-linked WorkOrderPartReturnEvidence ids uploaded for this planned part.
     */
    public function returnPart(WorkOrderPlannedPart $part, float $quantity, string $condition, ?string $userId, ?string $reason = null, ?string $evidence = null, array $evidenceIds = []): WorkOrderPlannedPart
    {
        if ($quantity <= 0) {
            throw new WorkOrderException('Return quantity must be positive.');
        }
        if (! in_array($condition, self::NEW_PART_CONDITIONS, true)) {
            throw new WorkOrderException('Only a part that was not used can be returned here (New Good / New Faulty). Record a component taken off the vehicle under Removed Components.');
        }

        return DB::transaction(function () use ($part, $quantity, $condition, $userId, $reason, $evidence, $evidenceIds) {
            $locked = WorkOrderPlannedPart::query()->lockForUpdate()->findOrFail($part->id);

            // Business rule: a Consumed line is material already used in the Work Order —
            // it can never be returned through Issuance & Return (only the still-unconsumed
            // remainder of a partially consumed line is returnable). Components physically
            // removed from the unit are returned through Removed Components instead.
            if ($locked->status === 'CONSUMED' || $locked->returnableQuantity() <= 0) {
                throw new WorkOrderException('Consumed parts cannot be returned through Issuance & Return. Use Removed Components to return components removed from the unit.');
            }
            QuantityPolicy::assertValidForProductId($locked->product_id, $quantity);
            if ($quantity > $locked->returnableQuantity()) {
                throw new WorkOrderException(Messages::text('errors.workOrder.returnExceedsReturnable', ['returnable' => $locked->returnableQuantity()]));
            }

            $workOrder = WorkOrder::query()->findOrFail($locked->work_order_id);
            $this->execution->assertExecutable($workOrder);
            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
            $product = Product::query()->findOrFail($locked->product_id);

            $number = $this->numbers->generate('part_return', $locked->tenant_id, $workOrder->branch_id, $workOrder->workshop_id, $warehouse->id);

            $return = WorkOrderPartReturn::query()->create([
                'tenant_id' => $locked->tenant_id,
                'return_number' => $number['document_number'],
                'return_source' => WorkOrderPartReturn::SOURCE_NEW_PART,
                'work_order_id' => $workOrder->id,
                'work_order_planned_part_id' => $locked->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'condition' => $condition,
                'disposition_status' => 'PENDING_PROCESSING',
                'returned_by' => $userId,
                'reason' => $reason,
                'evidence' => $evidence,
            ]);

            if ($evidenceIds !== []) {
                WorkOrderPartReturnEvidence::query()
                    ->where('work_order_planned_part_id', $locked->id)
                    ->whereNull('work_order_part_return_id')
                    ->whereIn('id', $evidenceIds)
                    ->update(['work_order_part_return_id' => $return->id]);
            }

            $locked->increment('returned_quantity', $quantity);
            $this->recomputeStatus($locked->fresh());

            return $locked->fresh();
        });
    }

    /**
     * G-22: locks the planned-part row so a concurrent return/consume pair
     * can't both pass their outstandingIssued() check against stale data,
     * then writes an immutable CONSUME ledger entry alongside the counter
     * update (previously the counter was the only record of consumption).
     */
    public function consume(WorkOrderPlannedPart $part, ?float $quantity, ?string $userId = null): WorkOrderPlannedPart
    {
        return DB::transaction(function () use ($part, $quantity, $userId) {
            $locked = WorkOrderPlannedPart::query()->lockForUpdate()->findOrFail($part->id);

            $toConsume = $quantity ?? $locked->outstandingIssued();
            if ($toConsume <= 0) {
                throw new WorkOrderException('Nothing left to consume for this planned part.');
            }
            if ($toConsume > $locked->outstandingIssued()) {
                throw new WorkOrderException('Cannot consume more than the outstanding issued quantity.');
            }

            if ($locked->stock_condition !== 'USED') {
                // A used tire left the used tire quantity at Issue; its installation is the record.
                $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
                $product = Product::query()->findOrFail($locked->product_id);
                $this->inventory->recordConsumption($warehouse, $product, $toConsume, WorkOrderPlannedPart::class, $locked->id, $userId);
            }

            $locked->increment('consumed_quantity', $toConsume);
            $this->recomputeStatus($locked->fresh());

            // Tire Operation replacement: the consumed serials are installed on their positions.
            $this->tireOperations->onReplacementConsumed($locked, (float) $toConsume, $userId);

            return $locked->fresh();
        });
    }

    /**
     * USED line (REUSE tires of a Tire Operation replacement): one serial at a time out of the
     * warehouse's used tire quantity. Used tires carry no stock valuation, so the issue cost is 0.
     *
     * @return array{unit_cost: float, total_cost: float}
     */
    private function issueUsedTires(WorkOrderPlannedPart $part, Warehouse $warehouse, float $quantity, ?string $userId): array
    {
        if (floor($quantity) !== $quantity) {
            throw new WorkOrderException('Used tires are issued per serial number: use a whole quantity.');
        }
        foreach ($this->tireOperations->usedReplacementTires($part, (int) $quantity) as $tire) {
            $this->usedStock->issue($tire, $warehouse->id, WorkOrderPlannedPart::class, $part->id, $userId);
        }

        return ['unit_cost' => 0.0, 'total_cost' => 0.0];
    }

    private function recomputeStatus(WorkOrderPlannedPart $part): void
    {
        $planned = (float) $part->planned_quantity;
        $reserved = (float) $part->reserved_quantity;
        $issued = (float) $part->issued_quantity;
        $consumed = (float) $part->consumed_quantity;
        $returned = (float) $part->returned_quantity;

        $status = match (true) {
            $issued > 0 && $returned >= $issued => 'RETURNED',
            $issued > 0 && $consumed >= $issued => 'CONSUMED',
            $issued > 0 && $issued >= $planned => 'ISSUED',
            $issued > 0 => 'PARTIALLY_ISSUED',
            $reserved > 0 && $reserved >= $planned => 'RESERVED',
            $reserved > 0 => 'PARTIALLY_RESERVED',
            default => $part->status === 'CANCELLED' ? 'CANCELLED' : 'PLANNED',
        };

        $part->update(['status' => $status]);
    }
}
