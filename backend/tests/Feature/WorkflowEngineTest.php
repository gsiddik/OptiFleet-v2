<?php

namespace Tests\Feature;

use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use App\Domain\Workflow\Models\WorkflowApprovalStep;
use App\Domain\Workflow\Services\ApprovalResolver;
use App\Domain\Workflow\Services\ConditionEvaluator;
use App\Domain\Workflow\Services\WorkflowApprovalService;
use App\Domain\Workflow\Services\WorkflowDefinitionService;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Services\WorkflowException;
use App\Domain\Workflow\Services\WorkflowValidationException;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowEngineTest extends TestCase
{
    private function publishWorkflow(WorkflowDefinitionService $service, string $tenantId, array $payload): \App\Domain\Configuration\Models\ConfigurationVersion
    {
        $set = $service->findOrCreateSet($tenantId, 'test_resource', 'TENANT', null, 'Test Resource Workflow');

        return $service->publish($service->createDraft($set, $payload, null), null);
    }

    private function simplePayload(?string $permission = 'work_order.view'): array
    {
        return [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'SUBMITTED', 'display_name' => 'Submitted'],
            ],
            'transitions' => [
                ['from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'action_code' => 'submit', 'action_label' => 'Submit', 'required_permission' => $permission],
            ],
        ];
    }

    // --- Validation ---

    public function test_publish_rejects_workflow_with_no_start_status(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF1-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $draft = $service->createDraft($set, [
            'statuses' => [['code' => 'DRAFT', 'display_name' => 'Draft']],
            'transitions' => [],
        ], null);

        $this->expectException(WorkflowValidationException::class);
        $service->publish($draft, null);
    }

    public function test_publish_rejects_duplicate_status_codes(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF2-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $draft = $service->createDraft($set, [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'DRAFT', 'display_name' => 'Draft again'],
            ],
            'transitions' => [],
        ], null);

        $this->expectException(WorkflowValidationException::class);
        $service->publish($draft, null);
    }

    public function test_publish_rejects_unreachable_status(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF3-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $draft = $service->createDraft($set, [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'ORPHAN', 'display_name' => 'Never reached'],
            ],
            'transitions' => [],
        ], null);

        $this->expectException(WorkflowValidationException::class);
        $service->publish($draft, null);
    }

    public function test_publish_rejects_transition_to_unknown_status(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF4-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $draft = $service->createDraft($set, [
            'statuses' => [['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true]],
            'transitions' => [['from_status' => 'DRAFT', 'to_status' => 'NOPE', 'action_code' => 'go']],
        ], null);

        $this->expectException(WorkflowValidationException::class);
        $service->publish($draft, null);
    }

    public function test_publish_rejects_unknown_permission(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF5-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $draft = $service->createDraft($set, $this->simplePayload('totally.made.up.permission'), null);

        $this->expectException(WorkflowValidationException::class);
        $service->publish($draft, null);
    }

    public function test_publish_rejects_unknown_automated_action(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF6-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $payload = $this->simplePayload();
        $payload['transitions'][0]['automated_actions'] = ['DELETE_EVERYTHING'];
        $draft = $service->createDraft($set, $payload, null);

        $this->expectException(WorkflowValidationException::class);
        $service->publish($draft, null);
    }

    public function test_publish_accepts_intentional_status_loop(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF7-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $draft = $service->createDraft($set, [
            'statuses' => [
                ['code' => 'IN_PROGRESS', 'display_name' => 'In progress', 'is_start' => true],
                ['code' => 'QC_PENDING', 'display_name' => 'QC pending'],
                ['code' => 'REWORK', 'display_name' => 'Rework'],
            ],
            'transitions' => [
                ['from_status' => 'IN_PROGRESS', 'to_status' => 'QC_PENDING', 'action_code' => 'submit_qc'],
                ['from_status' => 'QC_PENDING', 'to_status' => 'REWORK', 'action_code' => 'fail_qc'],
                ['from_status' => 'REWORK', 'to_status' => 'IN_PROGRESS', 'action_code' => 'resume'], // loop back
            ],
        ], null);

        $version = $service->publish($draft, null);
        $this->assertSame('PUBLISHED', $version->fresh()->status);
    }

    public function test_publish_rejects_approval_rule_with_duplicate_step_numbers(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF8-'.Str::random(4)]);
        $service = app(WorkflowDefinitionService::class);
        $set = $service->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $payload = $this->simplePayload(null);
        $payload['transitions'][0]['approval_rule'] = [
            'type' => 'SEQUENTIAL',
            'steps' => [
                ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.approve'],
                ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.close'],
            ],
        ];
        $draft = $service->createDraft($set, $payload, null);

        $this->expectException(WorkflowValidationException::class);
        $service->publish($draft, null);
    }

    // --- Condition evaluator ---

    public function test_condition_evaluator_and_or_nested_groups(): void
    {
        $evaluator = new ConditionEvaluator;
        $context = ['cost' => 6000, 'category' => 'PART'];

        $this->assertTrue($evaluator->evaluate([
            'operator' => 'AND',
            'rules' => [
                ['field' => 'cost', 'op' => '>=', 'value' => 5000],
                ['operator' => 'OR', 'rules' => [
                    ['field' => 'category', 'op' => '=', 'value' => 'LABOR'],
                    ['field' => 'category', 'op' => '=', 'value' => 'PART'],
                ]],
            ],
        ], $context));

        $this->assertFalse($evaluator->evaluate([
            'operator' => 'AND',
            'rules' => [['field' => 'cost', 'op' => '<', 'value' => 5000]],
        ], $context));

        $this->assertTrue($evaluator->evaluate([
            'operator' => 'AND',
            'rules' => [['field' => 'missing_field', 'op' => 'IS_NULL']],
        ], $context));

        $this->assertTrue($evaluator->evaluate(null, $context)); // no condition = always applies
    }

    // --- Available transitions ---

    public function test_available_transitions_filters_by_permission_and_condition(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF9-'.Str::random(4)]);
        $definitions = app(WorkflowDefinitionService::class);
        $payload = [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'APPROVED', 'display_name' => 'Approved'],
                ['code' => 'ESCALATED', 'display_name' => 'Escalated'],
            ],
            'transitions' => [
                ['from_status' => 'DRAFT', 'to_status' => 'APPROVED', 'action_code' => 'approve', 'required_permission' => 'work_order.approve', 'condition_set' => ['operator' => 'AND', 'rules' => [['field' => 'cost', 'op' => '<', 'value' => 1000]]]],
                ['from_status' => 'DRAFT', 'to_status' => 'ESCALATED', 'action_code' => 'escalate', 'required_permission' => 'work_order.close'],
            ],
        ];
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $payload, null), null);

        [$user] = $this->makeTenantUser($tenant, ['work_order.approve']);

        $engine = app(WorkflowEngine::class);

        // Has permission + condition passes -> approve is available; escalate is not (no permission).
        $available = $engine->availableTransitions($version, 'DRAFT', $user, $tenant->id, ['cost' => 500]);
        $this->assertCount(1, $available);
        $this->assertSame('approve', $available[0]['action_code']);

        // Condition fails (cost too high) -> nothing available.
        $availableHighCost = $engine->availableTransitions($version, 'DRAFT', $user, $tenant->id, ['cost' => 5000]);
        $this->assertCount(0, $availableHighCost);
    }

    public function test_simulate_never_mutates_and_reports_approval_requirement(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF10-'.Str::random(4)]);
        $definitions = app(WorkflowDefinitionService::class);
        $payload = $this->simplePayload('work_order.approve');
        $payload['transitions'][0]['approval_rule'] = [
            'type' => 'SINGLE',
            'steps' => [['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.close']],
        ];
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $payload, null), null);

        [$user] = $this->makeTenantUser($tenant, ['work_order.approve']);

        $engine = app(WorkflowEngine::class);
        $before = WorkflowApprovalRequest::query()->count();
        $result = $engine->simulate($version, 'DRAFT', $user, $tenant->id, []);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['requires_approval']);
        $this->assertSame($before, WorkflowApprovalRequest::query()->count()); // no side effects
    }

    // --- Approval resolver + approval service ---

    public function test_approval_resolver_resolves_by_permission_role_and_explicit_user(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF11-'.Str::random(4)]);
        [$permUser] = $this->makeTenantUser($tenant, ['work_order.approve']);
        $resolver = app(ApprovalResolver::class);

        $byPermission = $resolver->resolveUserIds($tenant->id, 'PERMISSION', 'work_order.approve');
        $this->assertContains($permUser->id, $byPermission);

        $byExplicit = $resolver->resolveUserIds($tenant->id, 'EXPLICIT_USER', $permUser->id);
        $this->assertSame([$permUser->id], $byExplicit);
    }

    public function test_single_step_approval_completes_request_on_approve(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF12-'.Str::random(4)]);
        [$approver] = $this->makeTenantUser($tenant, ['work_order.approve']);
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $this->simplePayload(), null), null);

        $approvals = app(WorkflowApprovalService::class);
        $request = $approvals->createRequest(
            $tenant->id, 'test_resource', (string) Str::uuid(), $version->id, 'submit', 'DRAFT', 'SUBMITTED',
            ['type' => 'SINGLE', 'steps' => [['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.approve']]],
            [], null
        );

        $this->assertSame('PENDING', $request->status);
        $step = $request->steps->first();

        $approvals->decide($step, 'APPROVED', $approver->id);

        $this->assertTrue($approvals->isFullyApproved($request));
    }

    public function test_sequential_approval_requires_steps_in_order(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF13-'.Str::random(4)]);
        [$tier1] = $this->makeTenantUser($tenant, ['work_order.approve']);
        [$tier2] = $this->makeTenantUser($tenant, ['work_order.close']);
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $this->simplePayload(), null), null);

        $approvals = app(WorkflowApprovalService::class);
        $request = $approvals->createRequest(
            $tenant->id, 'test_resource', (string) Str::uuid(), $version->id, 'submit', 'DRAFT', 'SUBMITTED',
            [
                'type' => 'SEQUENTIAL',
                'steps' => [
                    ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.approve'],
                    ['step_number' => 2, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.close'],
                ],
            ],
            [], null
        );

        [$step1, $step2] = $request->steps->all();

        // Cannot decide step 2 before step 1.
        $this->expectException(WorkflowException::class);
        $approvals->decide($step2, 'APPROVED', $tier2->id);
    }

    public function test_conditional_approval_skips_step_whose_condition_is_false(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF14-'.Str::random(4)]);
        [$tier1] = $this->makeTenantUser($tenant, ['work_order.approve']);
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $this->simplePayload(), null), null);

        $approvals = app(WorkflowApprovalService::class);
        // Cost-tiered example: tier 2 (Fleet Manager) only required when cost >= 5000.
        $request = $approvals->createRequest(
            $tenant->id, 'test_resource', (string) Str::uuid(), $version->id, 'submit', 'DRAFT', 'SUBMITTED',
            [
                'type' => 'CONDITIONAL',
                'steps' => [
                    ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.approve'],
                    ['step_number' => 2, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.close', 'condition_set' => ['operator' => 'AND', 'rules' => [['field' => 'cost', 'op' => '>=', 'value' => 5000]]]],
                ],
            ],
            ['cost' => 800], null
        );

        [$step1, $step2] = $request->steps->all();
        $this->assertSame('SKIPPED', $step2->status);

        $approvals->decide($step1, 'APPROVED', $tier1->id);

        $this->assertTrue($approvals->isFullyApproved($request));
    }

    public function test_rejection_at_any_step_rejects_the_whole_request(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF15-'.Str::random(4)]);
        [$tier1] = $this->makeTenantUser($tenant, ['work_order.approve']);
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $this->simplePayload(), null), null);

        $approvals = app(WorkflowApprovalService::class);
        $request = $approvals->createRequest(
            $tenant->id, 'test_resource', (string) Str::uuid(), $version->id, 'submit', 'DRAFT', 'SUBMITTED',
            [
                'type' => 'SEQUENTIAL',
                'steps' => [
                    ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.approve'],
                    ['step_number' => 2, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.close'],
                ],
            ],
            [], null
        );

        $step1 = $request->steps->first();
        $approvals->decide($step1, 'REJECTED', $tier1->id);

        $this->assertSame('REJECTED', $request->fresh()->status);
        $this->assertSame('SKIPPED', $request->fresh()->steps->last()->status);
    }

    public function test_step_cannot_be_decided_twice(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF16-'.Str::random(4)]);
        [$approver] = $this->makeTenantUser($tenant, ['work_order.approve']);
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $this->simplePayload(), null), null);

        $approvals = app(WorkflowApprovalService::class);
        $request = $approvals->createRequest(
            $tenant->id, 'test_resource', (string) Str::uuid(), $version->id, 'submit', 'DRAFT', 'SUBMITTED',
            ['type' => 'SINGLE', 'steps' => [['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.approve']]],
            [], null
        );
        $step = $request->steps->first();
        $approvals->decide($step, 'APPROVED', $approver->id);

        $this->expectException(WorkflowException::class);
        $approvals->decide($step->fresh(), 'APPROVED', $approver->id);
    }

    public function test_ineligible_user_cannot_decide_step(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF17-'.Str::random(4)]);
        [$approver] = $this->makeTenantUser($tenant, ['work_order.approve']);
        [$outsider] = $this->makeTenantUser($tenant, []);
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $version = $definitions->publish($definitions->createDraft($set, $this->simplePayload(), null), null);

        $approvals = app(WorkflowApprovalService::class);
        $request = $approvals->createRequest(
            $tenant->id, 'test_resource', (string) Str::uuid(), $version->id, 'submit', 'DRAFT', 'SUBMITTED',
            ['type' => 'SINGLE', 'steps' => [['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'work_order.approve']]],
            [], null
        );
        $step = $request->steps->first();

        $this->expectException(WorkflowException::class);
        $approvals->decide($step, 'APPROVED', $outsider->id);
    }

    // --- Versioning / tenant isolation (reusing Batch A's proven guarantees, checked at the workflow layer too) ---

    public function test_workflow_versioning_preserves_history_and_in_flight_reference(): void
    {
        $tenant = $this->makeTenant(['code' => 'WF18-'.Str::random(4)]);
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'test_resource', 'TENANT', null, 'Test');
        $v1 = $definitions->publish($definitions->createDraft($set, $this->simplePayload(), null), null);
        $v1Id = $v1->id;

        $v2 = $definitions->publish($definitions->createDraft($set, $this->simplePayload('work_order.close'), null), null);

        $this->assertSame('ARCHIVED', $v1->fresh()->status);
        $this->assertSame('PUBLISHED', $v2->fresh()->status);
        // A resource that captured v1's id at issue time still finds that exact payload.
        $this->assertSame('work_order.view', \App\Domain\Configuration\Models\ConfigurationVersion::query()->find($v1Id)->payload['transitions'][0]['required_permission']);
    }

    public function test_workflow_resolution_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant(['code' => 'WF19A-'.Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'WF19B-'.Str::random(4)]);
        $definitions = app(WorkflowDefinitionService::class);
        $setA = $definitions->findOrCreateSet($tenantA->id, 'test_resource', 'TENANT', null, 'Test A');
        $definitions->publish($definitions->createDraft($setA, $this->simplePayload('work_order.view'), null), null);

        $engine = app(WorkflowEngine::class);
        $resolvedForB = $engine->resolveEffective('test_resource', $tenantB->id);

        $this->assertNull($resolvedForB); // tenant B sees nothing — no platform default seeded for 'test_resource'
    }
}
