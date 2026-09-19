<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Models\WorkOrderPartRequestItem;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5: Request Parts is a mechanic-initiated request/approval
 * document, deliberately kept separate from WorkOrderPlannedPart's own
 * Reserve -> Issue -> Consume/Return lifecycle (see WorkOrderPartService).
 * Approving a line here never touches warehouse_stocks directly — it
 * creates a WorkOrderPlannedPart via WorkOrderExecutionService, so every
 * stock mutation for an approved request still happens inside the one,
 * already-tested Planned Parts lifecycle.
 */
class WorkOrderPartRequestService
{
    public function __construct(
        private readonly WorkOrderExecutionService $execution,
    ) {}

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

                WorkOrderPartRequestItem::query()->create([
                    'tenant_id' => $workOrder->tenant_id,
                    'part_request_id' => $request->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_reference' => $item['product_reference'] ?? null,
                    'description' => $item['description'],
                    'quantity_requested' => $quantity,
                ]);
            }

            return $request->fresh('items');
        });
    }

    /**
     * @param  array<string, float>|null  $approvedQuantities  item id => approved quantity. Any
     *                                                          item not present defaults to its full quantity_requested.
     */
    public function approve(WorkOrderPartRequest $request, ?array $approvedQuantities, ?string $userId, ?string $note): WorkOrderPartRequest
    {
        return DB::transaction(function () use ($request, $approvedQuantities, $userId, $note) {
            $locked = WorkOrderPartRequest::query()->lockForUpdate()->with('items')->findOrFail($request->id);

            if ($locked->status !== 'REQUESTED') {
                throw new WorkOrderException('Only a requested part request can be approved.');
            }

            $workOrder = WorkOrder::query()->findOrFail($locked->work_order_id);
            $anyApproved = false;

            foreach ($locked->items as $item) {
                $requested = (float) $item->quantity_requested;
                $approvedQty = array_key_exists($item->id, $approvedQuantities ?? []) ? (float) $approvedQuantities[$item->id] : $requested;

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
            $locked = WorkOrderPartRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== 'REQUESTED') {
                throw new WorkOrderException('Only a requested part request can be rejected.');
            }

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
            $locked = WorkOrderPartRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== 'REQUESTED') {
                throw new WorkOrderException('Only a requested part request can be cancelled.');
            }

            $locked->update([
                'status' => 'CANCELLED',
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
