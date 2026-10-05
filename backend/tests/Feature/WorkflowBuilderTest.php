<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Workflow\Services\WorkflowCatalog;
use App\Domain\Workflow\Services\WorkflowGraphAnalyzer;
use Database\Seeders\WorkflowDefaultsSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Visual Workflow Builder contract: the builder edits the real versioned workflow (statuses +
 * transitions) the runtime enforces. A reconnected / added / removed arrow changes what the module
 * actually allows once published (for documents created after publishing — in-flight documents
 * keep their pinned version); the graph is validated as a whole (cycles allowed); node positions
 * are stored apart from the workflow logic.
 */
class WorkflowBuilderTest extends TestCase
{
    private function scenario(array $permissions = []): array
    {
        $this->seed(WorkflowDefaultsSeeder::class);
        $tenant = $this->makeTenant(['code' => 'WFB-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        [$user, $token] = $this->makeTenantUser($tenant, $permissions ?: [
            'configuration.view', 'workflow.manage', 'workflow.publish',
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review', 'maintenance_request.approve', 'maintenance_request.reject',
        ]);

        return ['tenant' => $tenant, 'vehicle' => $vehicle, 'user' => $user, 'headers' => $this->authHeaders($token)];
    }

    private function defaultPayload(string $code): array
    {
        return ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', 'WORKFLOW')->where('code', $code)->firstOrFail()->publishedVersion()->payload;
    }

    private function newRequest(array $s): string
    {
        return $this->postJson('/api/v1/app/maintenance-requests', ['vehicle_id' => $s['vehicle']->id, 'priority' => 'MEDIUM', 'complaint' => 'Noise'], $s['headers'])->assertStatus(201)->json('data.id');
    }

    private function publishTenantWorkflow(array $s, array $payload): string
    {
        $id = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'WORKFLOW', 'code' => 'maintenance_request', 'name' => 'Our MR workflow', 'payload' => $payload], $s['headers'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/configuration/versions/{$id}/publish", [], $s['headers'])->assertOk();

        return $id;
    }

    public function test_every_platform_default_is_a_valid_graph_and_defines_the_catalog(): void
    {
        $this->seed(WorkflowDefaultsSeeder::class);
        $analyzer = app(WorkflowGraphAnalyzer::class);
        $catalog = app(WorkflowCatalog::class);
        $sets = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', 'WORKFLOW')->get();
        $this->assertGreaterThanOrEqual(10, $sets->count());
        foreach ($sets as $set) {
            $result = $analyzer->analyze($set->publishedVersion()->payload, $catalog->forResource($set->code));
            $this->assertSame([], $result['errors'], "{$set->code}: ".json_encode($result['errors']));
        }

        $mr = $catalog->forResource('maintenance_request');
        $this->assertContains('APPROVED', $mr['targets']);
        $this->assertNotContains('DRAFT', $mr['targets'], 'nothing moves a maintenance request back into DRAFT');
        $this->assertContains(['code' => 'UNDER_REVIEW', 'display_name' => 'Under Review'], $mr['statuses']);
        $this->assertNull($catalog->forResource('no_such_resource'));
    }

    public function test_metadata_and_validation_report_every_graph_problem(): void
    {
        $s = $this->scenario();
        $meta = $this->getJson('/api/v1/app/configuration/metadata?type=WORKFLOW&code=work_order', $s['headers'])->assertOk()->json('data');
        $this->assertContains('QC_PENDING', array_column($meta['catalog']['statuses'], 'code'));
        $this->assertContains('work_order', array_column($meta['resource_types'], 'code'));

        $validate = fn (array $payload, string $code = 'maintenance_request') => $this->postJson('/api/v1/app/configuration/workflow/validate', ['code' => $code, 'payload' => $payload], $s['headers'])->assertOk()->json('data');
        $types = fn (array $r, string $k = 'errors') => array_column($r[$k], 'type');

        // The seeded default is valid; a backward edge (UNDER_REVIEW → SUBMITTED) is allowed, not a DAG error.
        $payload = $this->defaultPayload('maintenance_request');
        $this->assertSame([], $validate($payload)['errors']);
        $payload['transitions'][] = ['from_status' => 'UNDER_REVIEW', 'to_status' => 'SUBMITTED', 'action_code' => 'submitted', 'action_label' => 'Return to Submitted'];
        $this->assertSame([], $validate($payload)['errors'], 'cycles / backward transitions are valid');

        $broken = $this->defaultPayload('maintenance_request');
        $broken['transitions'][] = ['from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'action_code' => 'again'];      // duplicate transition
        $broken['transitions'][] = ['from_status' => 'APPROVED', 'to_status' => 'APPROVED', 'action_code' => 'self'];      // self transition
        $broken['transitions'][] = ['from_status' => 'APPROVED', 'to_status' => 'GHOST', 'action_code' => 'ghost'];        // invalid target
        $broken['transitions'][] = ['from_status' => 'SUBMITTED', 'to_status' => 'DRAFT', 'action_code' => 'back'];        // no action moves into DRAFT
        $broken['statuses'][] = ['code' => 'ON_HOLD', 'display_name' => 'On Hold'];                                         // not a status of this document
        $result = $validate($broken);
        foreach (['DUPLICATE_TRANSITION', 'SELF_TRANSITION', 'UNKNOWN_TO', 'NOT_EXECUTABLE', 'UNKNOWN_STATUS', 'UNREACHABLE'] as $type) {
            $this->assertContains($type, $types($result), "{$type} reported");
        }

        // Missing start; an unreachable chain (only reached from another unreachable status); orphan warning.
        $noStart = $this->defaultPayload('maintenance_request');
        $noStart['statuses'] = array_map(fn ($st) => ['is_start' => false] + $st, $noStart['statuses']);
        $this->assertContains('NO_START', $types($validate($noStart)));
        $chain = ['statuses' => [['code' => 'A', 'is_start' => true], ['code' => 'B'], ['code' => 'C'], ['code' => 'D', 'is_start' => true]],
            'transitions' => [['from_status' => 'B', 'to_status' => 'C', 'action_code' => 'c'], ['from_status' => 'C', 'to_status' => 'B', 'action_code' => 'b']]];
        $chainResult = $validate($chain, 'test_resource');
        $this->assertSame(['B', 'C'], array_column(array_filter($chainResult['errors'], fn ($e) => $e['type'] === 'UNREACHABLE'), 'status'));
        $this->assertSame(['A', 'D'], array_column($chainResult['warnings'], 'status'), 'unconnected statuses warned');

        // Removing a status a document can be in is only a warning.
        $removed = $this->defaultPayload('maintenance_request');
        $removed['statuses'] = array_values(array_filter($removed['statuses'], fn ($st) => $st['code'] !== 'NEED_INFORMATION'));
        $removed['transitions'] = array_values(array_filter($removed['transitions'], fn ($t) => $t['from_status'] !== 'NEED_INFORMATION'));
        $r = $validate($removed);
        $this->assertSame([], $r['errors']);
        $this->assertSame(['STATUS_NOT_IN_WORKFLOW'], $types($r, 'warnings'));

        // Publishing enforces the same rules.
        $id = $this->postJson('/api/v1/app/configuration/versions', ['type' => 'WORKFLOW', 'code' => 'maintenance_request', 'name' => 'Bad', 'payload' => $broken], $s['headers'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/configuration/versions/{$id}/publish", [], $s['headers'])->assertStatus(422);
    }

    public function test_reconnected_added_and_removed_arrows_change_the_runtime_for_new_documents(): void
    {
        $s = $this->scenario();
        $before = $this->newRequest($s); // created under the platform default (pinned)

        // Reconnect DRAFT → SUBMITTED to DRAFT → APPROVED (target moved), remove DRAFT → CANCELLED,
        // and add a backward UNDER_REVIEW → SUBMITTED.
        $payload = $this->defaultPayload('maintenance_request');
        foreach ($payload['transitions'] as &$t) {
            if ($t['from_status'] === 'DRAFT' && $t['to_status'] === 'SUBMITTED') {
                $t = ['from_status' => 'DRAFT', 'to_status' => 'APPROVED', 'action_code' => 'approved', 'action_label' => 'Approve'];
            }
        }
        unset($t);
        $payload['transitions'] = array_values(array_filter($payload['transitions'], fn ($t) => ! ($t['from_status'] === 'DRAFT' && $t['to_status'] === 'CANCELLED')));
        // SUBMITTED keeps an incoming edge so it stays reachable; UNDER_REVIEW → SUBMITTED is a backward edge.
        $payload['transitions'][] = ['from_status' => 'UNDER_REVIEW', 'to_status' => 'SUBMITTED', 'action_code' => 'submitted', 'action_label' => 'Back to Submitted'];
        $payload['transitions'][] = ['from_status' => 'APPROVED', 'to_status' => 'SUBMITTED', 'action_code' => 'submitted', 'action_label' => 'Resubmit'];
        $versionId = $this->publishTenantWorkflow($s, $payload);

        $after = $this->newRequest($s);
        $this->assertSame($versionId, MaintenanceRequest::query()->find($after)->workflow_configuration_version_id, 'new documents pin the new version');
        $available = fn (string $id) => array_column($this->getJson("/api/v1/app/workflow/available-transitions?resource_type=maintenance_request&resource_id={$id}", $s['headers'])->assertOk()->json('data.transitions'), 'to_status');
        $this->assertSame(['APPROVED'], $available($after), 'the module offers exactly the configured transitions');
        $this->assertSame(['SUBMITTED', 'CANCELLED'], $available($before), 'documents in flight keep their workflow');

        $this->postJson("/api/v1/app/maintenance-requests/{$after}/submit", [], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/maintenance-requests/{$after}/cancel", ['note' => 'x'], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/maintenance-requests/{$after}/approve", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson("/api/v1/app/maintenance-requests/{$after}/submit", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->postJson("/api/v1/app/maintenance-requests/{$after}/review", [], $s['headers'])->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$after}/submit", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

        // The earlier document still follows the default (submit allowed, approve from DRAFT not).
        $this->postJson("/api/v1/app/maintenance-requests/{$before}/approve", [], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/maintenance-requests/{$before}/submit", [], $s['headers'])->assertOk();
    }

    public function test_layout_is_stored_apart_from_the_workflow_and_scoped(): void
    {
        $s = $this->scenario();
        $versionId = $this->publishTenantWorkflow($s, $this->defaultPayload('maintenance_request'));
        $payloadBefore = $this->getJson('/api/v1/app/configuration/sets?type=WORKFLOW', $s['headers'])->json('data');

        $this->getJson("/api/v1/app/configuration/versions/{$versionId}/layout", $s['headers'])->assertOk()->assertJsonPath('data.positions', null);
        $this->putJson("/api/v1/app/configuration/versions/{$versionId}/layout", [
            'positions' => ['DRAFT' => ['x' => 10, 'y' => 20.55], 'SUBMITTED' => ['x' => 260, 'y' => 20], 'NOT_A_STATUS' => ['x' => 1, 'y' => 1]],
            'viewport' => ['x' => 5, 'y' => 6, 'zoom' => 0.8],
        ], $s['headers'])->assertOk();
        $layout = $this->getJson("/api/v1/app/configuration/versions/{$versionId}/layout", $s['headers'])->assertOk()->json('data');
        $this->assertEquals(['DRAFT' => ['x' => 10, 'y' => 20.6], 'SUBMITTED' => ['x' => 260, 'y' => 20]], $layout['positions'], 'unknown statuses dropped');
        $this->assertEquals(0.8, $layout['viewport']['zoom']);
        $this->assertEquals($payloadBefore, $this->getJson('/api/v1/app/configuration/sets?type=WORKFLOW', $s['headers'])->json('data'), 'moving cards never changes the workflow or its versions');

        // The platform default's layout cannot be saved; other tenants cannot see or save this one.
        $default = ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', 'WORKFLOW')->where('code', 'maintenance_request')->firstOrFail()->publishedVersion();
        $this->getJson("/api/v1/app/configuration/versions/{$default->id}/layout", $s['headers'])->assertOk()->assertJsonPath('data.positions', null);
        $this->putJson("/api/v1/app/configuration/versions/{$default->id}/layout", ['positions' => ['DRAFT' => ['x' => 1, 'y' => 1]]], $s['headers'])->assertStatus(404);
        $other = $this->makeTenant(['code' => 'WFO-'.Str::random(4)]);
        [, $otherToken] = $this->makeTenantUser($other, ['configuration.view', 'workflow.manage']);
        $this->getJson("/api/v1/app/configuration/versions/{$versionId}/layout", $this->authHeaders($otherToken))->assertStatus(404);
        $this->putJson("/api/v1/app/configuration/versions/{$versionId}/layout", ['positions' => ['DRAFT' => ['x' => 1, 'y' => 1]]], $this->authHeaders($otherToken))->assertStatus(404);

        // Saving a layout needs workflow.manage.
        [, $viewer] = $this->makeTenantUser($s['tenant'], ['configuration.view']);
        $this->putJson("/api/v1/app/configuration/versions/{$versionId}/layout", ['positions' => ['DRAFT' => ['x' => 1, 'y' => 1]]], $this->authHeaders($viewer))->assertStatus(403);
    }

    public function test_available_transitions_respect_permissions_and_scope(): void
    {
        $s = $this->scenario();
        $id = $this->newRequest($s);
        [, $noView] = $this->makeTenantUser($s['tenant'], ['configuration.view']);
        $this->getJson("/api/v1/app/workflow/available-transitions?resource_type=maintenance_request&resource_id={$id}", $this->authHeaders($noView))->assertStatus(403);
        $this->getJson("/api/v1/app/workflow/available-transitions?resource_type=nope&resource_id={$id}", $s['headers'])->assertStatus(422);
        $other = $this->makeTenant(['code' => 'WFX-'.Str::random(4)]);
        [, $otherToken] = $this->makeTenantUser($other, ['maintenance_request.view']);
        $this->getJson("/api/v1/app/workflow/available-transitions?resource_type=maintenance_request&resource_id={$id}", $this->authHeaders($otherToken))->assertStatus(404);
    }
}
