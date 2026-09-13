<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use App\Domain\Workflow\Services\WorkflowApprovalService;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use Illuminate\Support\Facades\DB;

/**
 * G-15: Used Sparepart Processing. Every USED_GOOD/USED_FAULTY return from
 * Phase A (work_order_part_returns.disposition_status = PENDING_INSPECTION)
 * moves through inspect() -> proposeDisposition() -> decide() -> (internal)
 * finalize(). The approval step reuses the existing generic
 * WorkflowApprovalService rather than a bespoke mechanism (the report's
 * program-level acceptance criterion, §31) — this is that engine's first
 * real production caller. WorkflowApprovalService itself does not enforce
 * maker-checker (confirmed by reading it directly), so the "maker cannot
 * approve their own disposition" rule is enforced here, in decide().
 */
class UsedPartDispositionService
{
    private const RESOURCE_TYPE = 'used_part_disposition';

    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly WorkflowApprovalService $approvals,
        private readonly InventoryService $inventory,
    ) {}

    public function inspect(WorkOrderPartReturn $return, float $acceptedQuantity, string $condition, ?string $notes, string $userId, ?string $evidence = null): WorkOrderPartReturn
    {
        if (! in_array($condition, ['USED_GOOD', 'USED_FAULTY'], true)) {
            throw new WorkOrderException('Inspection condition must be USED_GOOD or USED_FAULTY.');
        }

        return DB::transaction(function () use ($return, $acceptedQuantity, $condition, $notes, $userId, $evidence) {
            $locked = WorkOrderPartReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->disposition_status !== 'PENDING_INSPECTION') {
                throw new WorkOrderException("Cannot inspect a return that is {$locked->disposition_status} (must be PENDING_INSPECTION).");
            }
            if ($acceptedQuantity <= 0 || $acceptedQuantity > (float) $locked->quantity) {
                throw new WorkOrderException('Accepted quantity must be positive and cannot exceed the originally returned quantity.');
            }

            $locked->update([
                'accepted_quantity' => $acceptedQuantity,
                // The inspector's classification is authoritative — it may correct the returner's initial guess.
                'condition' => $condition,
                'inspected_by' => $userId,
                'inspected_at' => now(),
                'inspection_notes' => $notes,
                // Distinct from the returner's own `evidence` — the inspector's own photo, if attached.
                'inspection_evidence' => $evidence,
                'disposition_status' => 'INSPECTED',
            ]);

            return $locked->fresh();
        });
    }

    public function proposeDisposition(WorkOrderPartReturn $return, string $disposition, ?string $reason, string $userId): WorkOrderPartReturn
    {
        if (! in_array($disposition, WorkOrderPartReturn::DISPOSITIONS, true)) {
            throw new WorkOrderException('Disposition must be one of: '.implode(', ', WorkOrderPartReturn::DISPOSITIONS).'.');
        }

        return DB::transaction(function () use ($return, $disposition, $reason, $userId) {
            $locked = WorkOrderPartReturn::query()->lockForUpdate()->findOrFail($return->id);

            if (! in_array($locked->disposition_status, ['INSPECTED', 'REJECTED'], true)) {
                throw new WorkOrderException("Cannot propose a disposition for a return that is {$locked->disposition_status} (must be INSPECTED or REJECTED).");
            }
            // Safety-first: a faulty item is never allowed to head toward reuse or sale without
            // first passing through Repair — this is enforced here, not left to the approver's judgment.
            if ($locked->condition === 'USED_FAULTY' && in_array($disposition, ['REUSE', 'SELL_ELIGIBLE'], true)) {
                throw new WorkOrderException('A USED_FAULTY item cannot be proposed for REUSE or SELL_ELIGIBLE — propose REPAIR, QUARANTINE, or SCRAP instead.');
            }

            $version = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $locked->tenant_id);
            if (! $version) {
                throw new WorkOrderException('No published used-part-disposition workflow configuration is available for this tenant.');
            }

            $request = $this->approvals->createRequest(
                $locked->tenant_id,
                self::RESOURCE_TYPE,
                $locked->id,
                $version->id,
                'approve',
                'PENDING_APPROVAL',
                'FINALIZED',
                ['type' => 'SINGLE', 'steps' => [
                    ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'used_part.approve'],
                ]],
                ['disposition' => $disposition, 'condition' => $locked->condition],
                $userId,
            );

            $locked->update([
                'disposition' => $disposition,
                'disposition_reason' => $reason,
                'proposed_by' => $userId,
                'workflow_configuration_version_id' => $version->id,
                'workflow_approval_request_id' => $request->id,
                'disposition_status' => 'PENDING_APPROVAL',
            ]);

            return $locked->fresh();
        });
    }

    public function decide(WorkOrderPartReturn $return, string $decision, string $userId, ?string $note = null): WorkOrderPartReturn
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new WorkOrderException("Invalid decision '{$decision}' — must be APPROVE or REJECT.");
        }

        return DB::transaction(function () use ($return, $decision, $userId, $note) {
            $locked = WorkOrderPartReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->disposition_status !== 'PENDING_APPROVAL') {
                throw new WorkOrderException("Cannot decide a disposition that is {$locked->disposition_status} (must be PENDING_APPROVAL).");
            }
            // Maker-checker: WorkflowApprovalService itself does not compare requested_by
            // against the deciding user, so this feature enforces it directly.
            if ($userId === $locked->proposed_by) {
                throw new WorkOrderException('The maker who proposed this disposition cannot also approve or reject it.');
            }

            $request = WorkflowApprovalRequest::query()->findOrFail($locked->workflow_approval_request_id);
            $step = $request->steps()->where('status', 'PENDING')->orderBy('step_number')->firstOrFail();
            $this->approvals->decide($step, $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED', $userId, $note);

            if ($decision === 'REJECT') {
                $locked->update(['disposition_status' => 'REJECTED']);

                return $locked->fresh();
            }

            $locked->update(['disposition_status' => 'FINALIZED', 'finalized_at' => now()]);
            $this->finalize($locked->fresh(), $userId);

            return $locked->fresh();
        });
    }

    /**
     * Executes the physical inventory consequence of an approved disposition.
     * Only REUSE ever touches warehouse_stocks: this is the first moment a
     * used-condition quantity is allowed to become available stock, and only
     * after inspection + approval (never automatically, per G-14/G-15).
     * REPAIR/QUARANTINE/SCRAP/SELL_ELIGIBLE deliberately cause no
     * InventoryService call at all — this quantity was never added to
     * quantity_on_hand at return time (Phase A), so there is nothing there
     * to decrement now; calling InventoryService::scrap() here would either
     * fail on insufficient on-hand or, worse, silently decrement unrelated
     * good stock for the same product. The WorkOrderPartReturn row itself
     * (Auditable, immutable condition/quantity, disposition + approver
     * trail) is the complete record for these four outcomes.
     */
    private function finalize(WorkOrderPartReturn $return, ?string $userId): void
    {
        if ($return->disposition !== 'REUSE') {
            return;
        }

        $warehouse = Warehouse::query()->findOrFail($return->warehouse_id);
        $product = Product::query()->findOrFail($return->product_id);
        $quantity = (float) ($return->accepted_quantity ?? $return->quantity);

        $this->inventory->returnStock(
            $warehouse, $product, $quantity, WorkOrderPartReturn::class, $return->id, $userId,
            'Used sparepart approved for reuse after inspection'
        );
    }
}
