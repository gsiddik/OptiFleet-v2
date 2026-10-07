<?php

namespace Database\Seeders;

use App\Domain\Shared\Support\Messages;
use App\Domain\Shared\Support\StatusLabels;
use App\Domain\Workflow\Services\WorkflowDefinitionService;
use App\Domain\Workflow\Support\WorkflowActionVerbs;
use Illuminate\Database\Seeder;

/**
 * Phase 5 Section 66: migrates every Phase 3/4 hardcoded TRANSITIONS map
 * into a platform-default WORKFLOW configuration with byte-identical
 * status/transition coverage, so existing behavior is preserved exactly —
 * the services below now validate transitions through WorkflowEngine
 * against these seeded definitions instead of their own hardcoded arrays.
 *
 * A few resources have statuses entered through a bespoke side-effect
 * method that bypasses the resource's own generic transition() entirely
 * (stock transfer dispatch()/receive(), PO status set by GoodsReceipt) —
 * those statuses are marked is_start=true (an additional valid entry
 * point into the graph) rather than invented transition rows, so the
 * seeded definition never grants transition() a path it didn't already
 * have.
 */
class WorkflowDefaultsSeeder extends Seeder
{
    /** Resource display names for the seeded workflow set names (never derived from the code). */
    private const RESOURCE_LABELS = [
        'maintenance_request' => 'Maintenance Request',
        'work_order' => 'Work Order',
        'vehicle_transfer' => 'Vehicle Transfer',
        'breakdown' => 'Breakdown',
        'stock_transfer' => 'Stock Transfer',
        'purchase_request' => 'Purchase Request',
        'purchase_order' => 'Purchase Order',
        'warranty_claim' => 'Warranty Claim',
        'used_part_disposition' => 'Used Part Disposition',
        'sparepart_sale' => 'Sparepart Sale',
        'stock_reconciliation' => 'Stock Reconciliation',
    ];

    public function run(): void
    {
        $service = app(WorkflowDefinitionService::class);

        foreach ($this->definitions() as $resourceType => $payload) {
            $this->seedPlatformDefault($service, $resourceType, $payload);
        }
    }

    private function seedPlatformDefault(WorkflowDefinitionService $service, string $resourceType, array $payload): void
    {
        $set = $service->findOrCreateSet(null, $resourceType, 'TENANT', null, Messages::text('workflow.defaults.setName', ['resourceType' => self::RESOURCE_LABELS[$resourceType]]), true);
        if ($set->publishedVersion()) {
            return;
        }
        $service->publish($service->createDraft($set, $payload, null, 'Initial platform default (migrated from hardcoded transitions)'), null);
    }

    /** Display name from the status label registry (i18n structural preparation); the code is canonical. */
    private function status(string $code, bool $start = false): array
    {
        return ['code' => $code, 'display_name' => StatusLabels::label($code), 'is_start' => $start];
    }

    /**
     * The action label is the owner-approved verb for the target status ("Approve" → APPROVED), not
     * the status name. The action code and statuses are unchanged.
     */
    private function transition(string $from, string $to): array
    {
        return ['from_status' => $from, 'to_status' => $to, 'action_code' => strtolower($to), 'action_label' => WorkflowActionVerbs::label($to) ?? StatusLabels::label($to)];
    }

    private function definitions(): array
    {
        return [
            'maintenance_request' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('SUBMITTED'), $this->status('UNDER_REVIEW'),
                    // NEED_INFORMATION — LEGACY, NO NEW TRANSITIONS. Kept in the graph only so any
                    // pre-existing legacy record retains its two exit transitions below; no status or
                    // transition here may target NEED_INFORMATION as a destination anymore. Marked
                    // is_start=true (same convention as other bespoke-side-effect-only statuses in this
                    // seeder) since it is no longer reachable via any live transition() call — only a
                    // pre-existing legacy row can ever hold it.
                    $this->status('NEED_INFORMATION', true), $this->status('APPROVED'), $this->status('WORK_ORDER_CREATED'),
                    $this->status('REJECTED'), $this->status('CANCELLED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'SUBMITTED'), $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('SUBMITTED', 'UNDER_REVIEW'), $this->transition('SUBMITTED', 'CANCELLED'),
                    // Reviewer actions are Approve/Reject only — Request Info / NEED_INFORMATION retired.
                    $this->transition('UNDER_REVIEW', 'APPROVED'), $this->transition('UNDER_REVIEW', 'REJECTED'),
                    $this->transition('UNDER_REVIEW', 'CANCELLED'),
                    // Legacy-only exit paths for any pre-existing NEED_INFORMATION record; not reachable
                    // from any new transition since nothing transitions INTO NEED_INFORMATION anymore.
                    $this->transition('NEED_INFORMATION', 'UNDER_REVIEW'), $this->transition('NEED_INFORMATION', 'CANCELLED'),
                    $this->transition('APPROVED', 'WORK_ORDER_CREATED'), $this->transition('APPROVED', 'CANCELLED'),
                ],
            ],
            'work_order' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('SUBMITTED'), $this->status('APPROVED'), $this->status('REJECTED'),
                    $this->status('ASSIGNED'), $this->status('SCHEDULED'), $this->status('IN_PROGRESS'), $this->status('QC_PENDING'),
                    $this->status('ON_HOLD'), $this->status('WAITING_PART'), $this->status('REWORK'), $this->status('COMPLETED'),
                    $this->status('CLOSED'), $this->status('CANCELLED'),
                    // EXTERNAL: a finalized External Work Order (Findings-only scope, carried out by
                    // an external workshop) — reachable only from DRAFT, never from SCHEDULED/
                    // IN_PROGRESS. Not a sub-state of IN_PROGRESS and not the separate
                    // WorkOrderExternalService towing/3rd-party-invoicing sub-resource, which is
                    // unrelated. See ExternalWorkOrderService for the Findings/finalize/revise/cancel
                    // rules the workflow engine alone cannot express.
                    $this->status('EXTERNAL'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'SUBMITTED'), $this->transition('DRAFT', 'CANCELLED'),
                    // Finalizing a Findings-only Draft into an External Work Order (ExternalWorkOrderService
                    // additionally requires execution_mode=EXTERNAL and >=1 Finding before allowing this).
                    $this->transition('DRAFT', 'EXTERNAL'),
                    $this->transition('SUBMITTED', 'APPROVED'), $this->transition('SUBMITTED', 'REJECTED'), $this->transition('SUBMITTED', 'CANCELLED'),
                    $this->transition('APPROVED', 'ASSIGNED'), $this->transition('APPROVED', 'CANCELLED'),
                    $this->transition('ASSIGNED', 'SCHEDULED'), $this->transition('ASSIGNED', 'CANCELLED'),
                    $this->transition('SCHEDULED', 'IN_PROGRESS'), $this->transition('SCHEDULED', 'CANCELLED'),
                    $this->transition('IN_PROGRESS', 'QC_PENDING'), $this->transition('IN_PROGRESS', 'ON_HOLD'),
                    $this->transition('IN_PROGRESS', 'WAITING_PART'), $this->transition('IN_PROGRESS', 'CANCELLED'),
                    $this->transition('ON_HOLD', 'IN_PROGRESS'), $this->transition('ON_HOLD', 'CANCELLED'),
                    $this->transition('WAITING_PART', 'IN_PROGRESS'), $this->transition('WAITING_PART', 'CANCELLED'),
                    // EXTERNAL: only Revise (back to Draft, re-finalizable) and Cancel — no other exit.
                    $this->transition('EXTERNAL', 'DRAFT'), $this->transition('EXTERNAL', 'CANCELLED'),
                    $this->transition('QC_PENDING', 'COMPLETED'), $this->transition('QC_PENDING', 'REWORK'),
                    $this->transition('REWORK', 'IN_PROGRESS'), // intentional loop back into the main flow
                    $this->transition('COMPLETED', 'CLOSED'),
                ],
            ],
            'vehicle_transfer' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('REQUESTED'), $this->status('APPROVED'), $this->status('REJECTED'),
                    $this->status('IN_TRANSIT'), $this->status('RECEIVED'), $this->status('COMPLETED'), $this->status('CANCELLED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'REQUESTED'), $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('REQUESTED', 'APPROVED'), $this->transition('REQUESTED', 'REJECTED'), $this->transition('REQUESTED', 'CANCELLED'),
                    $this->transition('APPROVED', 'IN_TRANSIT'), $this->transition('APPROVED', 'CANCELLED'),
                    $this->transition('IN_TRANSIT', 'RECEIVED'),
                    $this->transition('RECEIVED', 'COMPLETED'),
                ],
            ],
            'breakdown' => [
                'statuses' => [
                    $this->status('REPORTED', true), $this->status('VERIFIED'), $this->status('ASSESSED'),
                    $this->status('REPAIR_REQUIRED'), $this->status('WORK_ORDER_CREATED'), $this->status('RESOLVED'),
                ],
                'transitions' => [
                    $this->transition('REPORTED', 'VERIFIED'),
                    $this->transition('VERIFIED', 'ASSESSED'),
                    $this->transition('ASSESSED', 'REPAIR_REQUIRED'), $this->transition('ASSESSED', 'RESOLVED'),
                    $this->transition('REPAIR_REQUIRED', 'WORK_ORDER_CREATED'),
                    $this->transition('WORK_ORDER_CREATED', 'RESOLVED'),
                ],
            ],
            'stock_transfer' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('REQUESTED'), $this->status('APPROVED'), $this->status('REJECTED'),
                    $this->status('PREPARED'), $this->status('CANCELLED'),
                    $this->status('DISPATCHED', true), // entered via dispatch(), bypasses transition()
                    $this->status('IN_TRANSIT'),
                    $this->status('RECEIVED', true), // entered via receive(), bypasses transition()
                    $this->status('COMPLETED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'REQUESTED'), $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('REQUESTED', 'APPROVED'), $this->transition('REQUESTED', 'REJECTED'), $this->transition('REQUESTED', 'CANCELLED'),
                    $this->transition('APPROVED', 'PREPARED'), $this->transition('APPROVED', 'CANCELLED'),
                    $this->transition('PREPARED', 'CANCELLED'),
                    $this->transition('DISPATCHED', 'IN_TRANSIT'),
                    $this->transition('RECEIVED', 'COMPLETED'),
                ],
            ],
            'purchase_request' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('SUBMITTED'), $this->status('UNDER_REVIEW'),
                    $this->status('APPROVED'), $this->status('PROCUREMENT'), $this->status('REJECTED'), $this->status('CANCELLED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'SUBMITTED'), $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('SUBMITTED', 'UNDER_REVIEW'), $this->transition('SUBMITTED', 'CANCELLED'),
                    $this->transition('UNDER_REVIEW', 'APPROVED'), $this->transition('UNDER_REVIEW', 'REJECTED'), $this->transition('UNDER_REVIEW', 'CANCELLED'),
                    $this->transition('APPROVED', 'PROCUREMENT'), $this->transition('APPROVED', 'CANCELLED'),
                ],
            ],
            'purchase_order' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('SUBMITTED'), $this->status('APPROVED'), $this->status('REJECTED'),
                    $this->status('ISSUED'), $this->status('CANCELLED'),
                    $this->status('PARTIALLY_RECEIVED', true), // entered by GoodsReceiptService, never via transition()
                    $this->status('RECEIVED', true), // entered by GoodsReceiptService, never via transition()
                    $this->status('CLOSED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'SUBMITTED'), $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('SUBMITTED', 'APPROVED'), $this->transition('SUBMITTED', 'REJECTED'), $this->transition('SUBMITTED', 'CANCELLED'),
                    $this->transition('APPROVED', 'ISSUED'), $this->transition('APPROVED', 'CANCELLED'),
                    $this->transition('RECEIVED', 'CLOSED'),
                ],
            ],
            'warranty_claim' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('SUBMITTED'), $this->status('UNDER_REVIEW'),
                    $this->status('APPROVED'), $this->status('REJECTED'), $this->status('REPLACEMENT'),
                    $this->status('REPAIR'), $this->status('SETTLED'), $this->status('CLOSED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'SUBMITTED'),
                    $this->transition('SUBMITTED', 'UNDER_REVIEW'),
                    $this->transition('UNDER_REVIEW', 'APPROVED'), $this->transition('UNDER_REVIEW', 'REJECTED'),
                    $this->transition('APPROVED', 'REPLACEMENT'), $this->transition('APPROVED', 'REPAIR'),
                    $this->transition('REPLACEMENT', 'SETTLED'), $this->transition('REPAIR', 'SETTLED'),
                    $this->transition('SETTLED', 'CLOSED'), $this->transition('REJECTED', 'CLOSED'),
                ],
            ],
            // G-15: Used Sparepart Processing (Phase B). This resource type is not driven
            // through WorkOrderTransitionService-style transition() calls — the graph exists
            // so UsedPartDispositionService's approve/reject step has a real, versioned
            // ConfigurationVersion to stamp on its WorkflowApprovalRequest (Decision-8-style
            // "every disposition record persists the configuration version" discipline),
            // matching the shape every other resource type here already has.
            'used_part_disposition' => [
                'statuses' => [
                    $this->status('PENDING_INSPECTION', true), $this->status('INSPECTED'),
                    $this->status('PENDING_APPROVAL'), $this->status('REJECTED'), $this->status('FINALIZED'),
                ],
                'transitions' => [
                    $this->transition('PENDING_INSPECTION', 'INSPECTED'),
                    $this->transition('INSPECTED', 'PENDING_APPROVAL'),
                    $this->transition('PENDING_APPROVAL', 'FINALIZED'),
                    $this->transition('PENDING_APPROVAL', 'REJECTED'),
                    $this->transition('REJECTED', 'PENDING_APPROVAL'), // re-propose after rejection, no re-inspection required
                ],
            ],
            // G-16: Sell Sparepart. Same "stamp a real ConfigurationVersion for the
            // WorkflowApprovalRequest" role as used_part_disposition above.
            'sparepart_sale' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('PENDING_APPROVAL'),
                    $this->status('APPROVED'), $this->status('REJECTED'), $this->status('CANCELLED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'PENDING_APPROVAL'),
                    $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('PENDING_APPROVAL', 'APPROVED'),
                    $this->transition('PENDING_APPROVAL', 'REJECTED'),
                ],
            ],
            // Approval of a stock reconciliation adjustment (correction of an old installation that never left
            // the ledger). Stamps the configuration version on the WorkflowApprovalRequest; the apply step is a
            // separate permission-gated action (inventory_reconcile.manage), not a workflow transition.
            'stock_reconciliation' => [
                'statuses' => [
                    $this->status('PENDING_APPROVAL', true), $this->status('APPROVED'), $this->status('REJECTED'),
                ],
                'transitions' => [
                    $this->transition('PENDING_APPROVAL', 'APPROVED'),
                    $this->transition('PENDING_APPROVAL', 'REJECTED'),
                ],
            ],
        ];
    }
}
