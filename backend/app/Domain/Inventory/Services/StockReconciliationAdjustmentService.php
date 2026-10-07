<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\InstallationStockExit;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReconciliationAdjustment as Adjustment;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use App\Domain\Workflow\Services\WorkflowApprovalService;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Correction of an old installation whose unit never left the ledger: propose -> approve / reject -> apply.
 *
 *  - propose: only a PROVABLE_UNDEDUCTED installation (recomputed now) qualifies; the evidence is snapshotted.
 *    Dry-run / proposal never moves stock.
 *  - approve / reject: the generic WorkflowApprovalService (permission inventory_reconcile.approve); the maker who
 *    proposed can never decide their own proposal.
 *  - apply: a separate permission-gated step that re-validates under row locks (still no exit record, still provable,
 *    no later posted opname, stock sufficient), books an ISSUE through InventoryService NOW (never back-dated,
 *    referencing this adjustment), writes the RECONCILED exit record and marks the adjustment APPLIED — one
 *    transaction. A second apply, a concurrent apply, or a second adjustment for the same installation is refused.
 * Original installation / movement history is never edited or deleted.
 */
class StockReconciliationAdjustmentService
{
    public const RESOURCE_TYPE = 'stock_reconciliation';

    public const APPROVE_PERMISSION = 'inventory_reconcile.approve';

    public function __construct(
        private readonly SerializedStockReconciliationService $reconciliation,
        private readonly InventoryService $inventory,
        private readonly WorkflowEngine $workflow,
        private readonly WorkflowApprovalService $approvals,
    ) {}

    public function propose(string $tenantId, string $installationId, string $reason, string $userId): Adjustment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InventoryException('A reason is required.');
        }
        $candidate = $this->reconciliation->evaluate($tenantId, $installationId);
        if ($candidate === null) {
            throw new InventoryException('This installation is not a reconciliation candidate (it already has a stock exit record or does not exist).');
        }
        if ($candidate['category'] !== SerializedStockReconciliationService::UNDEDUCTED) {
            throw new InventoryException("Only a provable undeducted installation can be adjusted; this one is {$candidate['category']}.");
        }
        $version = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $tenantId);
        if (! $version) {
            throw new InventoryException('No published stock reconciliation workflow configuration is available for this tenant.');
        }

        try {
            return DB::transaction(function () use ($tenantId, $candidate, $reason, $userId, $version) {
                $adjustment = Adjustment::query()->create([
                    'tenant_id' => $tenantId, 'installation_class' => $candidate['installation_class'], 'installation_id' => $candidate['installation_id'],
                    'asset_type' => $candidate['kind'] === 'TIRE' ? InstallationStockExit::TYPE_TIRE : InstallationStockExit::TYPE_COMPONENT, 'asset_id' => $candidate['asset_id'],
                    'product_id' => $candidate['product_id'], 'warehouse_id' => $candidate['warehouse_id'], 'serial' => $candidate['serial'], 'installed_at' => $candidate['installed_at'],
                    'quantity' => 1, 'status' => Adjustment::PENDING, 'reason' => $reason, 'evidence' => $this->evidence($tenantId, $candidate),
                    'proposed_by' => $userId, 'proposed_at' => now(), 'workflow_configuration_version_id' => $version->id,
                ]);
                $request = $this->approvals->createRequest(
                    $tenantId, self::RESOURCE_TYPE, $adjustment->id, $version->id, 'approve', Adjustment::PENDING, Adjustment::APPROVED,
                    ['type' => 'SINGLE', 'steps' => [['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => self::APPROVE_PERMISSION]]],
                    ['installation_id' => $candidate['installation_id'], 'serial' => $candidate['serial']], $userId,
                );
                $adjustment->update(['workflow_approval_request_id' => $request->id]);

                return $adjustment->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InventoryException('An adjustment for this installation already exists (pending, approved or applied).');
        }
    }

    public function decide(string $tenantId, string $adjustmentId, string $decision, string $userId, ?string $note = null): Adjustment
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new InventoryException("Invalid decision '{$decision}' — must be APPROVE or REJECT.");
        }
        if ($decision === 'REJECT' && trim((string) $note) === '') {
            throw new InventoryException('A note is required to reject an adjustment.');
        }

        return DB::transaction(function () use ($tenantId, $adjustmentId, $decision, $userId, $note) {
            $locked = Adjustment::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($adjustmentId);
            if ($locked->status !== Adjustment::PENDING) {
                throw new InventoryException("Cannot decide an adjustment that is {$locked->status} (must be PENDING_APPROVAL).");
            }
            // WorkflowApprovalService does not compare maker and checker: enforced here.
            if ($locked->proposed_by === $userId) {
                throw new InventoryException('The maker who proposed this adjustment cannot also approve or reject it.');
            }
            $request = WorkflowApprovalRequest::query()->findOrFail($locked->workflow_approval_request_id);
            $step = $request->steps()->where('status', 'PENDING')->orderBy('step_number')->firstOrFail();
            $this->approvals->decide($step, $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED', $userId, $note);
            $locked->update(['status' => $decision === 'APPROVE' ? Adjustment::APPROVED : Adjustment::REJECTED, 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note]);

            return $locked->fresh();
        });
    }

    public function apply(string $tenantId, string $adjustmentId, string $userId): Adjustment
    {
        $superseded = null;
        $result = DB::transaction(function () use ($tenantId, $adjustmentId, $userId, &$superseded) {
            $locked = Adjustment::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($adjustmentId);
            if ($locked->status === Adjustment::APPLIED) {
                throw new InventoryException('This adjustment was already applied.');
            }
            if ($locked->status !== Adjustment::APPROVED) {
                throw new InventoryException("Only an approved adjustment can be applied; this one is {$locked->status}.");
            }
            // Serialise with a concurrent install / reconciliation of the same unit.
            DB::table('component_assets')->where('id', $locked->asset_id)->lockForUpdate()->first();
            if (InstallationStockExit::query()->where('installation_id', $locked->installation_id)->exists()) {
                $superseded = 'The installation already has a stock exit record; nothing left to correct.';
                $locked->update(['status' => Adjustment::SUPERSEDED, 'decision_note' => trim(($locked->decision_note ?? '').' [superseded: exit record exists]')]);

                return $locked;
            }
            $candidate = $this->reconciliation->evaluate($tenantId, $locked->installation_id);
            if ($candidate === null || $candidate['category'] !== SerializedStockReconciliationService::UNDEDUCTED) {
                $category = $candidate['category'] ?? 'NO_LONGER_A_CANDIDATE';
                $superseded = "Evidence changed since approval ({$category}); the adjustment was superseded and nothing was booked.";
                $locked->update(['status' => Adjustment::SUPERSEDED, 'decision_note' => trim(($locked->decision_note ?? '')." [superseded: {$category}]")]);

                return $locked;
            }

            $warehouse = Warehouse::query()->where('tenant_id', $tenantId)->findOrFail($locked->warehouse_id);
            $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($locked->product_id);
            $this->inventory->issue($warehouse, $product, (float) $locked->quantity, Adjustment::class, $locked->id, $userId,
                "Stock reconciliation adjustment: unit {$locked->serial} installed on {$locked->installed_at?->toDateString()} left the warehouse without a ledger issue. Reason: {$locked->reason}");
            $movementId = StockMovement::query()->where('reference_type', Adjustment::class)->where('reference_id', $locked->id)->where('movement_type', 'ISSUE')->value('id');
            InstallationStockExit::query()->create([
                'tenant_id' => $tenantId, 'asset_type' => $locked->asset_type, 'asset_id' => $locked->asset_id, 'installation_id' => $locked->installation_id,
                'product_id' => $locked->product_id, 'warehouse_id' => $locked->warehouse_id, 'source' => InstallationStockExit::SOURCE_DIRECT, 'reason' => 'RECONCILED',
                'stock_movement_id' => $movementId, 'created_by' => $userId,
            ]);
            $locked->update(['status' => Adjustment::APPLIED, 'applied_by' => $userId, 'applied_at' => now(), 'stock_movement_id' => $movementId]);

            return $locked->fresh();
        });
        if ($superseded !== null) {
            throw new InventoryException($superseded);
        }

        return $result;
    }

    /** @return array<string, mixed> What the reviewer saw: classification, origin evidence and the opname / stock state at proposal time. */
    private function evidence(string $tenantId, array $c): array
    {
        $onHand = DB::table('warehouse_stocks')->where('tenant_id', $tenantId)->where('warehouse_id', $c['warehouse_id'])->where('product_id', $c['product_id'])->value('quantity_on_hand');

        return [
            'category' => $c['category'], 'evidence' => $c['evidence'], 'goods_receipt_item_id' => $c['goods_receipt_item_id'], 'work_order_id' => $c['work_order_id'],
            'vehicle' => $c['registration_number'] ?? null, 'movement_check' => 'NO_COVERING_WO_ISSUE_AND_NO_EXIT_RECORD',
            'ledger_on_hand_at_proposal' => number_format((float) $onHand, 4, '.', ''), 'opname' => $c['opname'] ?? null,
        ];
    }
}
