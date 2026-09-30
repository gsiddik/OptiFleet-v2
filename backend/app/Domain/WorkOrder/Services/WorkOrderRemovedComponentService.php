<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Models\WorkOrderRemovedComponent;
use App\Domain\WorkOrder\Models\WorkOrderRemovedComponentEvidence;
use App\Domain\WorkOrder\Models\WorkOrderRemovedComponentReturn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Owner decision: the old/removed-component domain — a component taken
 * OFF the vehicle when a replacement part is installed, later returned
 * to a warehouse. Deliberately separate from WorkOrderPartService (which
 * only ever represents warehouse-issued stock returning, whether
 * installed or not). Same gating (assertExecutable — Draft/External
 * excluded) as the rest of the active-execution part lifecycle.
 */
class WorkOrderRemovedComponentService
{
    private const ALLOWED_EVIDENCE_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const MAX_EVIDENCE_SIZE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly WorkOrderExecutionService $execution,
        private readonly InventoryService $inventory,
    ) {}

    public function remove(WorkOrder $workOrder, array $attributes, ?string $userId): WorkOrderRemovedComponent
    {
        $this->execution->assertExecutable($workOrder);

        if ((float) $attributes['quantity'] <= 0) {
            throw new WorkOrderException('Removed quantity must be positive.');
        }
        if (! in_array($attributes['condition'], WorkOrderRemovedComponent::CONDITIONS, true)) {
            throw new WorkOrderException('Condition must be one of: '.implode(', ', WorkOrderRemovedComponent::CONDITIONS).'.');
        }

        QuantityPolicy::assertValidForProductId($attributes['product_id'] ?? null, $attributes['quantity']);

        return DB::transaction(function () use ($workOrder, $attributes, $userId) {
            // Serialize removals per Work Order so the consumed-quantity cap cannot be raced.
            WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $replacedBy = $this->replacementFor($workOrder, $attributes['product_id'], (float) $attributes['quantity']);

            $component = WorkOrderRemovedComponent::query()->create([
                'tenant_id' => $workOrder->tenant_id,
                'work_order_id' => $workOrder->id,
                'maintenance_job_id' => $attributes['maintenance_job_id'] ?? null,
                'replaced_by_planned_part_id' => $replacedBy->id,
                'product_id' => $attributes['product_id'],
                'quantity' => $attributes['quantity'],
                'condition' => $attributes['condition'],
                'notes' => $attributes['notes'] ?? null,
                'status' => 'PENDING_RETURN',
                'removed_by' => $userId,
                'removed_at' => now(),
            ]);

            // Every removed component is a Used Sparepart Processing item from the moment it is
            // recorded (unique per component, so it can never be queued twice).
            WorkOrderPartReturn::query()->create([
                'tenant_id' => $workOrder->tenant_id,
                'return_source' => WorkOrderPartReturn::SOURCE_REMOVED_COMPONENT,
                'work_order_id' => $workOrder->id,
                'work_order_removed_component_id' => $component->id,
                'product_id' => $component->product_id,
                'quantity' => $component->quantity,
                'condition' => $component->condition === 'GOOD' ? 'USED_GOOD' : 'USED_FAULTY',
                'disposition_status' => 'PENDING_RETURN',
                'returned_by' => $userId,
            ]);

            return $component;
        });
    }

    /**
     * A removed (old) component can only be recorded for a product CONSUMED on this Work Order —
     * the new part installed in its place — and never for more than was consumed. The replacement
     * link is derived here (the consumed line of the same product), never chosen by the user.
     */
    private function replacementFor(WorkOrder $workOrder, string $productId, float $quantity): WorkOrderPlannedPart
    {
        $consumedLines = WorkOrderPlannedPart::query()
            ->where('work_order_id', $workOrder->id)->where('product_id', $productId)->where('consumed_quantity', '>', 0)
            ->orderBy('created_at')->orderBy('id')->get();
        if ($consumedLines->isEmpty()) {
            throw new WorkOrderException('Only a part consumed on this Work Order can be recorded as a removed component.');
        }

        $consumed = (float) $consumedLines->sum('consumed_quantity');
        $alreadyRemoved = (float) WorkOrderRemovedComponent::query()
            ->where('work_order_id', $workOrder->id)->where('product_id', $productId)->sum('quantity');
        if ($alreadyRemoved + $quantity > $consumed + 0.00001) {
            $remaining = max(0, $consumed - $alreadyRemoved);
            throw new WorkOrderException("Removed quantity cannot exceed the consumed quantity of this product on the Work Order (remaining: {$remaining}).");
        }

        return $consumedLines->first();
    }

    public function delete(WorkOrderRemovedComponent $removedComponent): void
    {
        $this->execution->assertExecutable($removedComponent->workOrder);
        if ($removedComponent->status !== 'PENDING_RETURN') {
            throw new WorkOrderException('A returned removed-component record cannot be deleted.');
        }

        // Its (still PENDING_RETURN) processing record is removed with it (FK cascade).
        $removedComponent->delete();
    }

    /**
     * Unlike work_order_part_return_evidence (one Planned Part can be returned in several
     * separate batches over time, so its evidence needs an explicit link step), a Removed
     * Component has exactly one lifecycle and exactly one return — evidence uploaded against
     * `work_order_removed_component_id` is unambiguous from the moment it's uploaded, so no
     * separate linking step is needed here.
     */
    /**
     * The old component physically reaches a warehouse (zero-balance ledger entry only) and
     * its Used Sparepart Processing record moves to PENDING_INSPECTION. From the Work Order
     * this is part of execution ($fromWorkOrder); from Used Sparepart Processing the
     * warehouse can receive it after the Work Order has moved on.
     */
    public function returnToWarehouse(WorkOrderRemovedComponent $removedComponent, string $warehouseId, ?string $reason, ?string $userId, bool $fromWorkOrder = true): WorkOrderRemovedComponent
    {
        return DB::transaction(function () use ($removedComponent, $warehouseId, $reason, $userId, $fromWorkOrder) {
            $locked = WorkOrderRemovedComponent::query()->lockForUpdate()->findOrFail($removedComponent->id);
            if ($locked->status !== 'PENDING_RETURN') {
                throw new WorkOrderException('This removed component has already been returned.');
            }

            $workOrder = WorkOrder::query()->findOrFail($locked->work_order_id);
            if ($fromWorkOrder) {
                $this->execution->assertExecutable($workOrder);
            }
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            abort_unless($warehouse->tenant_id === $locked->tenant_id, 404);
            $product = Product::query()->findOrFail($locked->product_id);

            // Zero-balance-effect ledger entry only — an old/removed component must never
            // silently become normal available stock (owner decision).
            $movement = $this->inventory->recordRemovedComponentReturn(
                $warehouse, $product, (float) $locked->quantity,
                WorkOrderRemovedComponent::class, $locked->id, $userId, $reason,
            );

            $return = WorkOrderRemovedComponentReturn::query()->create([
                'tenant_id' => $locked->tenant_id,
                'work_order_removed_component_id' => $locked->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $locked->quantity,
                'stock_movement_id' => $movement->id,
                'reason' => $reason,
                'returned_by' => $userId,
            ]);

            $locked->update(['status' => 'RETURNED']);

            WorkOrderPartReturn::query()->where('work_order_removed_component_id', $locked->id)->lockForUpdate()->first()
                ?->update(['warehouse_id' => $warehouse->id, 'disposition_status' => 'PENDING_INSPECTION']);

            return $locked->fresh(['return']);
        });
    }

    public function uploadEvidence(WorkOrderRemovedComponent $removedComponent, UploadedFile $file, string $uploadedByUserId): WorkOrderRemovedComponentEvidence
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_EVIDENCE_MIME_TYPES, true)) {
            throw new WorkOrderException('Unsupported file type. Only JPG or PNG images are accepted.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png'], true)) {
            throw new WorkOrderException('Unsupported file extension. Only .jpg, .jpeg, or .png are accepted.');
        }
        if ($file->getSize() > self::MAX_EVIDENCE_SIZE_BYTES) {
            throw new WorkOrderException('File exceeds the 5MB maximum size.');
        }

        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "work-order-removed-components/{$removedComponent->tenant_id}",
            Str::uuid().'.'.$extension,
            ['disk' => 'local']
        );

        return WorkOrderRemovedComponentEvidence::query()->create([
            'tenant_id' => $removedComponent->tenant_id,
            'work_order_removed_component_id' => $removedComponent->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploadedByUserId,
        ]);
    }

    public function deleteEvidence(WorkOrderRemovedComponentEvidence $evidence): void
    {
        if ($evidence->removedComponent->status !== 'PENDING_RETURN') {
            throw new WorkOrderException('Evidence on an already-returned removed component cannot be removed.');
        }

        Storage::disk($evidence->disk)->delete($evidence->path);
        $evidence->delete();
    }
}
