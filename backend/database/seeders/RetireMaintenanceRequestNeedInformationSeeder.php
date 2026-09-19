<?php

namespace Database\Seeders;

use App\Domain\Workflow\Services\WorkflowDefinitionService;
use Illuminate\Database\Seeder;

/**
 * Publishes an updated "maintenance_request" platform-default workflow
 * version that drops the UNDER_REVIEW -> NEED_INFORMATION transition
 * (Request Info retired at the application level). Idempotent: does
 * nothing once the currently published version no longer contains that
 * transition. The two legacy exit transitions
 * (NEED_INFORMATION -> UNDER_REVIEW / CANCELLED) are preserved so any
 * pre-existing record left in NEED_INFORMATION keeps a valid way out.
 *
 * This does not touch already-created requests still pinned to an older
 * workflow_configuration_version_id — only new transition attempts made
 * against this newly-published version are affected, and the
 * requestInfo()/request-info route this transition depended on has
 * already been removed, so the old path is unreachable end-to-end
 * regardless of which version a given request happens to be pinned to.
 */
class RetireMaintenanceRequestNeedInformationSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet(null, 'maintenance_request', 'TENANT', null, 'Maintenance Request Workflow', true);
        $published = $set->publishedVersion();

        if (! $published) {
            return;
        }

        $transitions = collect($published->payload['transitions'] ?? []);
        $stillHasRetiredTransition = $transitions->contains(
            fn (array $t) => ($t['from_status'] ?? null) === 'UNDER_REVIEW' && ($t['to_status'] ?? null) === 'NEED_INFORMATION'
        );

        if (! $stillHasRetiredTransition) {
            return;
        }

        $newTransitions = $transitions->reject(
            fn (array $t) => ($t['from_status'] ?? null) === 'UNDER_REVIEW' && ($t['to_status'] ?? null) === 'NEED_INFORMATION'
        )->values()->all();

        // NEED_INFORMATION is no longer reachable via any live transition — mark it is_start=true
        // (same convention this seeder already uses for bespoke-side-effect-only statuses) so the
        // workflow validator doesn't reject the new version as containing an orphaned status.
        $newStatuses = collect($published->payload['statuses'] ?? [])
            ->map(function (array $s) {
                if (($s['code'] ?? null) === 'NEED_INFORMATION') {
                    $s['is_start'] = true;
                }

                return $s;
            })->values()->all();

        $payload = $published->payload;
        $payload['transitions'] = $newTransitions;
        $payload['statuses'] = $newStatuses;

        $draft = $service->createDraft($set, $payload, null, 'Retire Request Info: remove UNDER_REVIEW -> NEED_INFORMATION (app-level only, enum value kept for legacy records)');
        $service->publish($draft, null);
    }
}
