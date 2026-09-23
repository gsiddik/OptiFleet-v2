<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPartEstimate;

/**
 * The doc's true "Planned Parts" tab — pure budgeting, never touches
 * warehouse stock (see the 2026_09_28_000004 migration's docblock).
 * Gated by the same PLANNING window (Draft through active execution) the
 * Jobs/Mechanic/Request-Parts-add actions already use — this is add/
 * delete-only estimation, so there is no separate "executable" gate to
 * worry about the way Reserve/Issue/Consume/Return need.
 */
class WorkOrderPlannedPartEstimateService
{
    public function __construct(private readonly WorkOrderExecutionService $execution) {}

    public function add(WorkOrder $workOrder, array $attributes, ?string $userId): WorkOrderPlannedPartEstimate
    {
        $this->execution->assertPlanningEditable($workOrder);

        if ((float) $attributes['quantity'] <= 0) {
            throw new WorkOrderException('Quantity must be positive.');
        }

        $product = Product::query()->findOrFail($attributes['product_id']);
        if (! in_array($product->product_type, WorkOrderPlannedPartEstimate::ALLOWED_PRODUCT_TYPES, true)) {
            throw new WorkOrderException('Only Sparepart, Tire, or Consumable products can be planned here.');
        }

        return WorkOrderPlannedPartEstimate::query()->create([
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'quantity' => $attributes['quantity'],
            'notes' => $attributes['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    public function delete(WorkOrderPlannedPartEstimate $estimate): void
    {
        $this->execution->assertPlanningEditable($estimate->workOrder);

        $estimate->delete();
    }
}
