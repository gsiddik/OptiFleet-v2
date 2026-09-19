<?php

namespace Database\Seeders;

use App\Domain\Workflow\Services\WorkflowDefinitionService;
use Illuminate\Database\Seeder;

/**
 * Corrects the "work_order" workflow graph published by
 * AddWorkOrderExternalStatusSeeder, which modeled EXTERNAL as reachable
 * from SCHEDULED/IN_PROGRESS and exiting to IN_PROGRESS/QC_PENDING. Per
 * the consolidated External Workshop business rules, EXTERNAL is a
 * Findings-only finalization reachable ONLY from DRAFT, exiting only via
 * Revise (-> DRAFT) or Cancel (-> CANCELLED). Idempotent: does nothing
 * once the published version no longer contains the old SCHEDULED ->
 * EXTERNAL transition.
 */
class CorrectWorkOrderExternalTransitionsSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet(null, 'work_order', 'TENANT', null, 'Work Order Workflow', true);
        $published = $set->publishedVersion();

        if (! $published) {
            return;
        }

        $transitions = collect($published->payload['transitions'] ?? []);
        $hasOldGraph = $transitions->contains(
            fn (array $t) => ($t['from_status'] ?? null) === 'SCHEDULED' && ($t['to_status'] ?? null) === 'EXTERNAL'
        );

        if (! $hasOldGraph) {
            return;
        }

        $oldExternalRelated = fn (array $t) => in_array($t['from_status'] ?? null, ['SCHEDULED', 'IN_PROGRESS', 'EXTERNAL'], true)
            && in_array($t['to_status'] ?? null, ['EXTERNAL', 'IN_PROGRESS', 'QC_PENDING', 'CANCELLED'], true)
            && (($t['from_status'] ?? null) === 'EXTERNAL' || ($t['to_status'] ?? null) === 'EXTERNAL');

        $newTransitions = $transitions->reject($oldExternalRelated)
            ->concat([
                ['from_status' => 'DRAFT', 'to_status' => 'EXTERNAL', 'action_code' => 'external', 'action_label' => 'External'],
                ['from_status' => 'EXTERNAL', 'to_status' => 'DRAFT', 'action_code' => 'revise', 'action_label' => 'Revise'],
                ['from_status' => 'EXTERNAL', 'to_status' => 'CANCELLED', 'action_code' => 'cancel', 'action_label' => 'Cancel'],
            ])->values()->all();

        $payload = $published->payload;
        $payload['transitions'] = $newTransitions;

        $draft = $service->createDraft($set, $payload, null, 'Correct EXTERNAL transitions: Findings-only finalization reachable only from DRAFT, exiting only via Revise/Cancel');
        $service->publish($draft, null);
    }
}
