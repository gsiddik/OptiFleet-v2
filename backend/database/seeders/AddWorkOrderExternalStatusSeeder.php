<?php

namespace Database\Seeders;

use App\Domain\Workflow\Services\WorkflowDefinitionService;
use Illuminate\Database\Seeder;

/**
 * Publishes an updated "work_order" platform-default workflow version that
 * adds the EXTERNAL status (top-level, parallel to IN_PROGRESS — the work
 * is being carried out by an external workshop) plus its transitions:
 * SCHEDULED/IN_PROGRESS -> EXTERNAL, and EXTERNAL -> IN_PROGRESS /
 * QC_PENDING / CANCELLED. Idempotent: does nothing once the currently
 * published version already contains the EXTERNAL status.
 *
 * Does not touch already-created Work Orders still pinned to an older
 * workflow_configuration_version_id — only new transition attempts made
 * against this newly-published version can reach EXTERNAL.
 */
class AddWorkOrderExternalStatusSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet(null, 'work_order', 'TENANT', null, 'Work Order Workflow', true);
        $published = $set->publishedVersion();

        if (! $published) {
            return;
        }

        $statuses = collect($published->payload['statuses'] ?? []);
        if ($statuses->contains(fn (array $s) => ($s['code'] ?? null) === 'EXTERNAL')) {
            return;
        }

        $newStatuses = $statuses->push([
            'code' => 'EXTERNAL', 'display_name' => 'External', 'is_start' => false,
        ])->values()->all();

        $newTransitions = collect($published->payload['transitions'] ?? [])
            ->concat([
                ['from_status' => 'SCHEDULED', 'to_status' => 'EXTERNAL', 'action_code' => 'external', 'action_label' => 'External'],
                ['from_status' => 'IN_PROGRESS', 'to_status' => 'EXTERNAL', 'action_code' => 'external', 'action_label' => 'External'],
                ['from_status' => 'EXTERNAL', 'to_status' => 'IN_PROGRESS', 'action_code' => 'in_progress', 'action_label' => 'In Progress'],
                ['from_status' => 'EXTERNAL', 'to_status' => 'QC_PENDING', 'action_code' => 'qc_pending', 'action_label' => 'Qc Pending'],
                ['from_status' => 'EXTERNAL', 'to_status' => 'CANCELLED', 'action_code' => 'cancelled', 'action_label' => 'Cancelled'],
            ])->values()->all();

        $payload = $published->payload;
        $payload['statuses'] = $newStatuses;
        $payload['transitions'] = $newTransitions;

        $draft = $service->createDraft($set, $payload, null, 'Add EXTERNAL status (top-level, parallel to IN_PROGRESS) for work carried out by an external workshop');
        $service->publish($draft, null);
    }
}
