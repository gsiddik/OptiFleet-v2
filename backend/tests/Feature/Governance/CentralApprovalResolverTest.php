<?php

namespace Tests\Feature\Governance;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Workflow\Models\WorkflowApprovalStep;
use App\Domain\Workflow\Services\ApprovalResolver;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

/**
 * Approvals in a centrally governed tenant (audit A02/R05, background and workflow paths): an approver is someone OptiNexus
 * lets in and, for a PERMISSION step, grants the permission to. A local role never makes anyone an approver; an outage means
 * nobody can approve (and nobody is notified), never "everybody".
 */
class CentralApprovalResolverTest extends TestCase
{
    use InteractsWithCentralGovernance;

    private ApprovalResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
        $this->resolver = app(ApprovalResolver::class);
    }

    private function step(string $type, string $identifier): WorkflowApprovalStep
    {
        return new WorkflowApprovalStep(['approver_type' => $type, 'approver_identifier' => $identifier]);
    }

    public function test_a_permission_step_is_open_to_exactly_the_people_optinexus_grants_it_to(): void
    {
        $tenant = $this->makeCentralTenant();
        [$approver] = $this->makeCentralUser($tenant, ['work_order.approve']);
        [$viewer] = $this->makeCentralUser($tenant, ['work_order.view']);
        // A local role holding the permission, but no grant in OptiNexus: not an approver.
        [$localOnly] = $this->makeTenantUser($tenant, ['work_order.approve']);
        $localOnly->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $this->nexus->grant($tenant->optinexus_tenant_id, $localOnly->optinexus_subject, ['work_order.view']);
        // Never signed in with OptiNexus: no central identity, no access.
        [$unlinked] = $this->makeTenantUser($tenant, ['work_order.approve']);

        $ids = $this->resolver->resolveUserIds($tenant->id, 'PERMISSION', 'work_order.approve');

        $this->assertSame([$approver->id], $ids);
        $this->assertTrue($this->resolver->userMatchesStep($this->step('PERMISSION', 'work_order.approve'), $tenant->id, $approver->id));
        foreach ([$viewer, $localOnly, $unlinked] as $other) {
            $this->assertFalse($this->resolver->userMatchesStep($this->step('PERMISSION', 'work_order.approve'), $tenant->id, $other->id));
        }
    }

    public function test_a_local_restriction_narrows_the_approver_never_widens(): void
    {
        $tenant = $this->makeCentralTenant();
        // OptiNexus grants approve; the local role (a restriction) only allows view: not an approver.
        [$restricted] = $this->makeCentralUser($tenant, ['work_order.approve', 'work_order.view'], ['work_order.view']);

        $this->assertFalse($this->resolver->userMatchesStep($this->step('PERMISSION', 'work_order.approve'), $tenant->id, $restricted->id));
    }

    public function test_an_explicit_user_must_still_be_let_in_by_optinexus_and_be_a_member(): void
    {
        $tenant = $this->makeCentralTenant();
        $other = $this->makeCentralTenant();
        [$member] = $this->makeCentralUser($tenant, ['work_order.view']);
        [$withdrawn] = $this->makeCentralUser($tenant, ['work_order.view']);
        [$foreign] = $this->makeCentralUser($other, ['work_order.view']);
        $this->nexus->withdraw($tenant->optinexus_tenant_id, $withdrawn->optinexus_subject);
        $this->forgetGovernance();

        $this->assertSame([$member->id], $this->resolver->resolveUserIds($tenant->id, 'EXPLICIT_USER', $member->id));
        $this->assertSame([], $this->resolver->resolveUserIds($tenant->id, 'EXPLICIT_USER', $withdrawn->id));
        $this->assertSame([], $this->resolver->resolveUserIds($tenant->id, 'EXPLICIT_USER', $foreign->id), 'a user of another tenant is never an approver');
    }

    public function test_a_role_step_still_needs_optinexus_to_let_the_person_in(): void
    {
        $tenant = $this->makeCentralTenant();
        [$in] = $this->makeCentralUser($tenant, ['work_order.view'], ['work_order.view']);
        [$out] = $this->makeCentralUser($tenant, ['work_order.view'], ['work_order.view']);
        $this->nexus->withdraw($tenant->optinexus_tenant_id, $out->optinexus_subject);
        $this->forgetGovernance();

        $role = RoleAssignment::query()->where('user_id', $in->id)->firstOrFail()->role;
        RoleAssignment::query()->where('user_id', $out->id)->update(['role_id' => $role->id]);

        $this->assertSame([$in->id], $this->resolver->resolveUserIds($tenant->id, 'ROLE', $role->name));
    }

    public function test_an_outage_notifies_nobody_and_refuses_the_decision(): void
    {
        $tenant = $this->makeCentralTenant();
        [$approver] = $this->makeCentralUser($tenant, ['work_order.approve']);
        $this->forgetGovernance();
        $this->nexus->mode = 'down';

        $this->assertSame([], $this->resolver->resolveUserIds($tenant->id, 'PERMISSION', 'work_order.approve'));

        $this->expectException(GovernanceUnavailableException::class);
        $this->resolver->userMatchesStep($this->step('PERMISSION', 'work_order.approve'), $tenant->id, $approver->id);
    }

    public function test_a_legacy_tenant_still_resolves_from_local_roles(): void
    {
        $tenant = $this->makeTenant();
        [$user] = $this->makeTenantUser($tenant, ['work_order.approve']);

        $this->assertSame([$user->id], $this->resolver->resolveUserIds($tenant->id, 'PERMISSION', 'work_order.approve'));
        $this->assertInstanceOf(User::class, $user);
    }
}
