<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Models\WorkOrderPartRequestItem;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Support\Facades\DB;

/**
 * Part Requests are the only issuing path for Work Order parts:
 *
 *   Work Order "Reserve" -> REQUESTED -> APPROVED | REJECTED | CANCELLED
 *                                        APPROVED -> ISSUED
 *
 * Approving a line creates its WorkOrderPlannedPart (the per-line Issue -> Consume/Return
 * bookkeeping); issuing posts the stock movement through WorkOrderPartService::issue() ->
 * InventoryService::issue() — the existing, tested inventory engine, never a parallel one.
 * Every transition locks the request row and is checked against
 * WorkOrderPartRequest::TRANSITIONS, so double approve/issue is rejected, never repeated.
 */
class WorkOrderPartRequestService
{
    public function __construct(
        private readonly WorkOrderExecutionService $execution,
        private readonly WorkOrderPartService $parts,
    ) {}

    /** @param array<int, array{product_id: string, quantity_requested: float|string}> $items */
    public function request(WorkOrder $workOrder, array $items, ?string $notes, ?string $userId): WorkOrderPartRequest
    {
        $this->execution->assertExecutable($workOrder);

        if (empty($items)) {
            throw new WorkOrderException('A part request must include at least one line item.');
        }

        return DB::transaction(function () use ($workOrder, $items, $notes, $userId) {
            $request = WorkOrderPartRequest::query()->create([
                'tenant_id' => $workOrder->tenant_id,
                'work_order_id' => $workOrder->id,
                'notes' => $notes,
                'status' => 'REQUESTED',
                'requested_by' => $userId,
                'requested_at' => now(),
            ]);

            foreach ($items as $item) {
                $quantity = (float) ($item['quantity_requested'] ?? 0);
                if ($quantity <= 0) {
                    throw new WorkOrderException('Each part request line must have a quantity greater than zero.');
                }

                // The Product master is the item's identity; the description is only its name snapshot.
                $product = Product::query()->where('status', 'ACTIVE')
                    ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $workOrder->tenant_id))
                    ->find($item['product_id'] ?? null);
                if (! $product) {
                    throw new WorkOrderException('Each part request line must reference an active Product.');
                }
                QuantityPolicy::assertValid($product, $item['quantity_requested'], 'quantity_requested');

                WorkOrderPartRequestItem::query()->create([
                    'tenant_id' => $workOrder->tenant_id,
                    'part_request_id' => $request->id,
                    'product_id' => $product->id,
                    'product_reference' => $product->sku,
                    'description' => $product->name,
                    'quantity_requested' => $quantity,
                ]);
            }

            return $request->fresh('items.product');
        });
    }

    /**
     * @param  array<string, float>|null  $approvedQuantities  item id => approved quantity. Any
     *                                                          item not present defaults to its full quantity_requested.
     */
    public function approve(WorkOrderPartRequest $request, ?array $approvedQuantities, ?string $userId, ?string $note): WorkOrderPartRequest
    {
        return DB::transaction(function () use ($request, $approvedQuantities, $userId, $note) {
            $locked = $this->lockFor($request, 'APPROVED');

            $workOrder = WorkOrder::query()->findOrFail($locked->work_order_id);
            $anyApproved = false;

            foreach ($locked->items as $item) {
                $requested = (float) $item->quantity_requested;
                $approvedQty = array_key_exists($item->id, $approvedQuantities ?? []) ? (float) $approvedQuantities[$item->id] : $requested;

                QuantityPolicy::assertValidForProductId($item->product_id, $approvedQty, 'quantity_approved');
                if ($approvedQty < 0 || $approvedQty > $requested) {
                    throw new WorkOrderException("Approved quantity for line \"{$item->description}\" must be between 0 and the requested quantity.");
                }

                $plannedPartId = null;
                if ($approvedQty > 0) {
                    $anyApproved = true;
                    $plannedPart = $this->execution->addPlannedPart($workOrder, [
                        'product_id' => $item->product_id,
                        'product_reference' => $item->product_reference,
                        'description' => $item->description,
                        'quantity' => $approvedQty,
                    ]);
                    $plannedPartId = $plannedPart->id;
                }

                $item->update(['quantity_approved' => $approvedQty, 'planned_part_id' => $plannedPartId]);
            }

            if (! $anyApproved) {
                throw new WorkOrderException('At least one line must be approved with a quantity greater than zero — use reject() otherwise.');
            }

            $locked->update([
                'status' => 'APPROVED',
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $locked->fresh('items.plannedPart');
        });
    }

    public function reject(WorkOrderPartRequest $request, string $reason, ?string $userId): WorkOrderPartRequest
    {
        if (trim($reason) === '') {
            throw new WorkOrderException('A reason is required to reject a part request.');
        }

        return DB::transaction(function () use ($request, $reason, $userId) {
            $locked = $this->lockFor($request, 'REJECTED');
            $locked->update([
                'status' => 'REJECTED',
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_note' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    public function cancel(WorkOrderPartRequest $request, ?string $userId): WorkOrderPartRequest
    {
        return DB::transaction(function () use ($request, $userId) {
            $locked = $this->lockFor($request, 'CANCELLED');
            $locked->update([
                'status' => 'CANCELLED',
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Issues every approved line from one warehouse, all-or-nothing: if any line lacks
     * stock the whole issue rolls back and nothing is deducted. The request row lock plus
     * the APPROVED -> ISSUED check make a second (double-click / concurrent) issue fail
     * instead of deducting stock twice.
     */
    public function issue(WorkOrderPartRequest $request, Warehouse $warehouse, ?string $userId): WorkOrderPartRequest
    {
        return DB::transaction(function () use ($request, $warehouse, $userId) {
            $locked = $this->lockFor($request, 'ISSUED');
            abort_unless($warehouse->tenant_id === $locked->tenant_id, 404);

            foreach ($locked->items as $item) {
                if (! $item->planned_part_id) {
                    continue; // a line approved with quantity 0
                }
                $part = WorkOrderPlannedPart::query()->lockForUpdate()->findOrFail($item->planned_part_id);
                $remaining = (float) $part->planned_quantity - (float) $part->issued_quantity;
                if ($remaining <= 0) {
                    continue;
                }
                if ($part->warehouse_id !== null && $part->warehouse_id !== $warehouse->id && (float) $part->reserved_quantity > 0) {
                    throw new WorkOrderException("Line \"{$item->description}\" is reserved in another warehouse — issue it from that warehouse.");
                }
                $part->update(['warehouse_id' => $warehouse->id]);

                try {
                    $this->parts->issue($part->fresh(), $remaining, $userId);
                } catch (InventoryException $e) {
                    throw new WorkOrderException("Line \"{$item->description}\": {$e->getMessage()}");
                }
            }

            $locked->update([
                'status' => 'ISSUED',
                'warehouse_id' => $warehouse->id,
                'issued_by' => $userId,
                'issued_at' => now(),
            ]);

            return $locked->fresh(['items.plannedPart', 'items.product']);
        });
    }

    /** Row-locks the request and rejects a transition its current status does not allow. */
    private function lockFor(WorkOrderPartRequest $request, string $target): WorkOrderPartRequest
    {
        $locked = WorkOrderPartRequest::query()->lockForUpdate()->with('items')->findOrFail($request->id);

        if (! $locked->canTransitionTo($target)) {
            $message = match (true) {
                $target === 'ISSUED' && $locked->status === 'REQUESTED' => 'This part request must be approved before it can be issued.',
                $target === 'ISSUED' && $locked->status === 'ISSUED' => 'This part request has already been issued.',
                $target === 'ISSUED' => "A {$locked->status} part request cannot be issued.",
                default => 'Only a requested part request can be '.strtolower($target === 'CANCELLED' ? 'cancelled' : $target).'.',
            };
            throw new WorkOrderException($message);
        }

        return $locked;
    }
}
