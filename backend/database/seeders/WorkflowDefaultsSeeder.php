<?php

namespace Database\Seeders;

use App\Domain\Workflow\Services\WorkflowDefinitionService;
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
    public function run(): void
    {
        $service = app(WorkflowDefinitionService::class);

        foreach ($this->definitions() as $resourceType => $payload) {
            $this->seedPlatformDefault($service, $resourceType, $payload);
        }
    }

    private function seedPlatformDefault(WorkflowDefinitionService $service, string $resourceType, array $payload): void
    {
        $set = $service->findOrCreateSet(null, $resourceType, 'TENANT', null, ucwords(str_replace('_', ' ', $resourceType)).' Workflow', true);
        if ($set->publishedVersion()) {
            return;
        }
        $service->publish($service->createDraft($set, $payload, null, 'Initial platform default (migrated from hardcoded transitions)'), null);
    }

    private function status(string $code, bool $start = false): array
    {
        return ['code' => $code, 'display_name' => ucwords(strtolower(str_replace('_', ' ', $code))), 'is_start' => $start];
    }

    private function transition(string $from, string $to): array
    {
        return ['from_status' => $from, 'to_status' => $to, 'action_code' => strtolower($to), 'action_label' => ucwords(strtolower(str_replace('_', ' ', $to)))];
    }

    private function definitions(): array
    {
        return [
            'maintenance_request' => [
                'statuses' => [
                    $this->status('DRAFT', true), $this->status('SUBMITTED'), $this->status('UNDER_REVIEW'),
                    $this->status('NEED_INFORMATION'), $this->status('APPROVED'), $this->status('WORK_ORDER_CREATED'),
                    $this->status('REJECTED'), $this->status('CANCELLED'),
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'SUBMITTED'), $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('SUBMITTED', 'UNDER_REVIEW'), $this->transition('SUBMITTED', 'CANCELLED'),
                    $this->transition('UNDER_REVIEW', 'APPROVED'), $this->transition('UNDER_REVIEW', 'REJECTED'),
                    $this->transition('UNDER_REVIEW', 'NEED_INFORMATION'), $this->transition('UNDER_REVIEW', 'CANCELLED'),
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
                ],
                'transitions' => [
                    $this->transition('DRAFT', 'SUBMITTED'), $this->transition('DRAFT', 'CANCELLED'),
                    $this->transition('SUBMITTED', 'APPROVED'), $this->transition('SUBMITTED', 'REJECTED'), $this->transition('SUBMITTED', 'CANCELLED'),
                    $this->transition('APPROVED', 'ASSIGNED'), $this->transition('APPROVED', 'CANCELLED'),
                    $this->transition('ASSIGNED', 'SCHEDULED'), $this->transition('ASSIGNED', 'CANCELLED'),
                    $this->transition('SCHEDULED', 'IN_PROGRESS'), $this->transition('SCHEDULED', 'CANCELLED'),
                    $this->transition('IN_PROGRESS', 'QC_PENDING'), $this->transition('IN_PROGRESS', 'ON_HOLD'),
                    $this->transition('IN_PROGRESS', 'WAITING_PART'), $this->transition('IN_PROGRESS', 'CANCELLED'),
                    $this->transition('ON_HOLD', 'IN_PROGRESS'), $this->transition('ON_HOLD', 'CANCELLED'),
                    $this->transition('WAITING_PART', 'IN_PROGRESS'), $this->transition('WAITING_PART', 'CANCELLED'),
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
        ];
    }
}
