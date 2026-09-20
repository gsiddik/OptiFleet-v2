<?php

namespace Database\Seeders;

use App\Domain\Workflow\Services\WorkflowDefinitionService;
use Illuminate\Database\Seeder;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice" Section 9: a Work Order's External Invoice becoming PAID must
 * trigger the Work Order itself moving EXTERNAL -> CLOSED (skipping the
 * internal QC_PENDING/COMPLETED path entirely, since External work never
 * goes through internal QC). Idempotent: does nothing once the published
 * graph already contains this transition.
 */
class AddWorkOrderExternalClosedTransitionSeeder extends Seeder
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
        $alreadyPresent = $transitions->contains(
            fn (array $t) => ($t['from_status'] ?? null) === 'EXTERNAL' && ($t['to_status'] ?? null) === 'CLOSED'
        );

        if ($alreadyPresent) {
            return;
        }

        $newTransitions = $transitions->concat([
            ['from_status' => 'EXTERNAL', 'to_status' => 'CLOSED', 'action_code' => 'close_external', 'action_label' => 'Close (External Invoice Paid)'],
        ])->values()->all();

        $payload = $published->payload;
        $payload['transitions'] = $newTransitions;

        $draft = $service->createDraft($set, $payload, null, 'Add EXTERNAL -> CLOSED transition, triggered when the External Invoice becomes Paid');
        $service->publish($draft, null);
    }
}
