<?php

namespace Tests\Feature\Governance;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Governance\Delegation\DelegationErrors;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\Support\Messages;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\Support\FakeNexusDelegation;
use Tests\TestCase;

/**
 * Onboarding by invitation (OptiNexus contract GOVERNANCE_CONTRACTS.md section 2b), consumer side: an Admin Tenant invites a new
 * person from OptiFleet, OptiNexus e-mails the link and grants access when the person accepts. Against FakeNexusDelegation, which
 * enforces the documented rules (one open invitation per e-mail, cooldown, limit, idempotent revoke, no token in any answer).
 */
class InvitationDelegationTest extends TestCase
{
    use InteractsWithCentralGovernance;

    private const ACCESS = ['user.view', 'user.create', 'user.assign', 'user.update', 'role.view', 'role.create', 'role.update', 'role.assign_permission'];

    private const DELEGABLE = [
        ['permission' => 'optifleet.vehicle.view', 'name' => 'Vehicle: View', 'description' => null, 'capability' => 'optifleet.vehicle'],
        ['permission' => 'optifleet.branch.view', 'name' => 'Branch: View', 'description' => null, 'capability' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    /** @return array{0: Tenant, 1: User, 2: string} tenant, the Admin Tenant (signed in with OptiNexus), token */
    private function adminTenant(array $permissions = self::ACCESS): array
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [$admin, $token] = $this->makeCentralUser($tenant, $permissions);
        FakeNexusDelegation::seed($this->nexus, $tenant->optinexus_tenant_id, [$admin->optinexus_subject], self::DELEGABLE);
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invite_cooldown'] = 0; // the cooldown has its own tests

        return [$tenant, $admin, $token];
    }

    private function state(Tenant $tenant): array
    {
        return $this->nexus->delegation[$tenant->optinexus_tenant_id];
    }

    /** @return array<int, array{method: string, path: string, query: array, body: array, scope: ?string}> */
    private function invitationCalls(): array
    {
        return array_values(array_filter($this->nexus->calls, fn (array $c) => str_contains($c['path'], '/invitations')));
    }

    private function role(Tenant $tenant, string $name = 'Clerk'): string
    {
        $id = (string) Str::uuid();
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['roles'][$id] = ['id' => $id, 'code' => strtoupper($name), 'name' => $name, 'description' => null, 'template' => false, 'editable' => true, 'permissions' => ['optifleet.vehicle.view']];

        return $id;
    }

    private function invite(string $token, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/app/delegation/invitations', $body + ['email' => 'New.Person@Example.test', 'name' => 'New Person'], $this->authHeaders($token));
    }

    // ------------------------------------------------------------ create

    public function test_inviting_sends_the_acting_user_and_the_tenant_from_the_session_and_audits_it(): void
    {
        [$tenant, $admin, $token] = $this->adminTenant();
        $role = $this->role($tenant);

        $response = $this->invite($token, [
            'role_ids' => [$role], 'locale' => 'id',
            // Everything below is something a client must not be able to decide.
            'acting_user_id' => (string) Str::uuid(), 'tenant_id' => (string) Str::uuid(), 'initial_password' => 'a-very-long-password-1', 'token' => 'x', 'password' => 'x',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.email', 'new.person@example.test')->assertJsonPath('data.locale', 'id')
            ->assertJsonPath('data.roles.0.name', 'Clerk')->assertJsonPath('data.delivery.status', 'QUEUED')->assertJsonPath('data.can_resend', true)->assertJsonPath('data.can_revoke', true);

        $calls = $this->invitationCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('/delegation/tenants/'.$tenant->optinexus_tenant_id.'/invitations', $calls[0]['path']);
        $this->assertSame($admin->optinexus_subject, $calls[0]['body']['acting_user_id']);
        $this->assertEqualsCanonicalizing(['email', 'name', 'role_ids', 'locale', 'acting_user_id'], array_keys($calls[0]['body']), 'only the documented fields are forwarded: no password, no token, no tenant');
        $this->assertSame('id', $calls[0]['body']['locale']);

        $audit = AuditLog::query()->where('action', 'delegation.invitation_created')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($response->json('data.id'), $audit->resource_id);
        $this->assertSame([$role], $audit->new_values['role_ids']);
        $this->assertSame($admin->optinexus_subject, $audit->new_values['actor_subject']);
    }

    public function test_the_language_of_the_invitation_follows_the_ui_language_unless_chosen(): void
    {
        [$tenant, $admin, $token] = $this->adminTenant();
        $admin->forceFill(['preferred_locale' => 'id'])->save();

        $this->invite($token, ['email' => 'a@example.test'])->assertCreated()->assertJsonPath('data.locale', 'id');
        $this->invite($token, ['email' => 'b@example.test', 'locale' => 'en'])->assertCreated()->assertJsonPath('data.locale', 'en');

        $before = count($this->invitationCalls());
        $this->invite($token, ['email' => 'c@example.test', 'locale' => 'fr'])->assertStatus(422)->assertJsonValidationErrors(['locale']);
        $this->assertSame($before, count($this->invitationCalls()), 'an invalid request never reaches OptiNexus');
        $this->assertCount(2, $this->state($tenant)['invitations']);
    }

    public function test_the_shape_of_the_request_is_checked_before_any_call(): void
    {
        [, , $token] = $this->adminTenant();

        $this->invite($token, ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->invite($token, ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->invite($token, ['role_ids' => ['not-a-uuid']])->assertStatus(422)->assertJsonValidationErrors(['role_ids.0']);
        $this->assertSame([], $this->invitationCalls());
    }

    public function test_refusals_of_the_invitation_contract_are_mapped_with_the_details_the_screen_needs(): void
    {
        [$tenant, , $token] = $this->adminTenant();

        // One open invitation per e-mail: the open one is named so the screen can point at Resend.
        $first = $this->invite($token)->assertCreated()->json('data.id');
        $this->invite($token)->assertStatus(409)->assertJsonPath('code', 'INVITATION_PENDING')->assertJsonPath('details.invitation_id', $first)
            ->assertJsonPath('message', Messages::text('errors.delegation.invitationPending', [], 'en'))->assertJsonPath('retryable', false)->assertJsonPath('outcome_unknown', false);

        // Somebody who already has access is not invited again.
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['identities']['member@example.test'] = $member = (string) Str::uuid();
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['users'][$member] = ['id' => $member, 'name' => 'M', 'email' => 'member@example.test', 'status' => 'ACTIVE',
            'membership_status' => 'ACTIVE', 'is_tenant_admin' => false, 'application_access' => 'ACTIVE', 'roles' => []];
        $this->invite($token, ['email' => 'member@example.test'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_MEMBER');

        // A role OptiNexus will not hand out.
        $unknown = (string) Str::uuid();
        $this->invite($token, ['email' => 'r@example.test', 'role_ids' => [$unknown]])->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_ASSIGNABLE')->assertJsonPath('details.role_ids.0', $unknown);

        // Rate limit of the invitation API: passed on with the wait OptiNexus asks for, never slept on or retried here.
        Sleep::fake();
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invite_rate_limit'] = 1;
        $calls = count($this->invitationCalls());
        $this->invite($token, ['email' => 'limit@example.test'])->assertStatus(429)->assertJsonPath('code', 'INVITATION_RATE_LIMITED')->assertJsonPath('retry_after_seconds', 120)
            ->assertHeader('Retry-After', '120')->assertJsonPath('outcome_unknown', false);
        $this->assertSame($calls + 1, count($this->invitationCalls()), 'one attempt, no retry');
        Sleep::assertNeverSlept();

        // Refusals are audited (an authorization-like decision); a rate limit decided nothing and is not.
        $this->assertSame(3, AuditLog::query()->where('action', 'delegation.refused')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(['ALREADY_MEMBER', 'INVITATION_PENDING', 'ROLE_NOT_ASSIGNABLE'], AuditLog::query()->where('action', 'delegation.refused')->get()->pluck('new_values.code')->sort()->values()->all());
    }

    public function test_a_person_who_is_not_an_admin_tenant_cannot_invite_or_list(): void
    {
        [$tenant] = $this->adminTenant();
        [$member, $token] = $this->makeCentralUser($tenant, self::ACCESS);
        $member->forceFill(['preferred_locale' => 'id'])->save();

        $this->invite($token)->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN')->assertJsonPath('message', Messages::text('errors.delegation.actorNotTenantAdmin', [], 'id'));
        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($token))->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->assertSame([], $this->state($tenant)['invitations']);
        $this->assertSame(2, AuditLog::query()->where('action', 'delegation.refused')->where('tenant_id', $tenant->id)->count());
    }

    // -------------------------------------------------------------- gates

    public function test_the_fleet_permission_gate_comes_first_for_every_invitation_route(): void
    {
        [$tenant, $admin, $adminToken] = $this->adminTenant();
        $id = $this->invite($adminToken)->assertCreated()->json('data.id');
        $this->nexus->reset();

        // Same permissions as the delegated user endpoints: view lists, create invites and resends, update revokes.
        [$viewer, $viewerToken] = $this->makeCentralUser($tenant, ['user.view']);
        [$creator, $creatorToken] = $this->makeCentralUser($tenant, ['user.view', 'user.create']);
        [$nobody, $nobodyToken] = $this->makeCentralUser($tenant, ['vehicle.view']);
        foreach ([$viewer, $creator] as $person) {
            $this->nexus->delegation[$tenant->optinexus_tenant_id]['admins'][] = $person->optinexus_subject;
        }

        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($nobodyToken))->assertForbidden();
        $this->invite($viewerToken)->assertForbidden();
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($viewerToken))->assertForbidden();
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/revoke", [], $this->authHeaders($creatorToken))->assertForbidden();
        $this->assertSame([], $this->invitationCalls(), 'OptiNexus is not asked when OptiFleet already says no');

        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($viewerToken))->assertOk();
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($creatorToken))->assertOk();
        $this->assertNotNull($admin);
        $this->assertNotNull($nobody);
    }

    public function test_a_legacy_tenant_has_no_invitations(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        [$user] = $this->makeTenantUser($tenant, self::ACCESS);
        $token = $user->createToken('t', ['tenant:'.$tenant->id])->plainTextToken;

        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'NOT_CENTRALLY_GOVERNED');
        $this->invite($token)->assertStatus(409)->assertJsonPath('code', 'NOT_CENTRALLY_GOVERNED');
        $this->assertSame([], $this->invitationCalls());
    }

    // --------------------------------------------------------------- list

    public function test_the_list_is_newest_first_filtered_by_status_and_search_and_shows_expired_invitations_as_such(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $ids = [];
        foreach (['ann', 'bob', 'cy'] as $name) {
            $ids[$name] = $this->invite($token, ['email' => "{$name}@example.test", 'name' => ucfirst($name)])->assertCreated()->json('data.id');
        }
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations'][$ids['ann']]['expires_at'] = now()->subHour()->toIso8601String();
        $this->postJson("/api/v1/app/delegation/invitations/{$ids['bob']}/revoke", ['reason' => 'wrong person'], $this->authHeaders($token))->assertOk();
        $accepted = $ids['cy'];
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations'][$accepted]['status'] = 'ACCEPTED';
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations'][$accepted]['accepted_at'] = now()->toIso8601String();

        $all = $this->getJson('/api/v1/app/delegation/invitations?per_page=50', $this->authHeaders($token))->assertOk();
        $all->assertJsonPath('meta.total', 3);
        $this->assertSame(['cy@example.test', 'bob@example.test', 'ann@example.test'], collect($all->json('data'))->pluck('email')->all());
        $this->assertSame(['ACCEPTED', 'REVOKED', 'EXPIRED'], collect($all->json('data'))->pluck('status')->all());

        $expired = collect($all->json('data'))->firstWhere('email', 'ann@example.test');
        $this->assertTrue($expired['can_resend'] && $expired['can_revoke'], 'an expired invitation can be resent or revoked');
        $done = collect($all->json('data'))->firstWhere('email', 'cy@example.test');
        $this->assertFalse($done['can_resend'] || $done['can_revoke'], 'an accepted invitation can be neither');
        $this->assertNotNull($done['accepted_at']);

        $this->assertSame(['ann@example.test'], collect($this->getJson('/api/v1/app/delegation/invitations?status=EXPIRED', $this->authHeaders($token))->json('data'))->pluck('email')->all());
        $this->assertSame(['bob@example.test'], collect($this->getJson('/api/v1/app/delegation/invitations?search=BOB', $this->authHeaders($token))->json('data'))->pluck('email')->all());
        $this->assertCount(1, $this->getJson('/api/v1/app/delegation/invitations?per_page=1', $this->authHeaders($token))->json('data'));

        $last = collect($this->invitationCalls())->last();
        $this->assertSame('1', (string) $last['query']['per_page']);
        $this->getJson('/api/v1/app/delegation/invitations?status=DONE', $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_the_invitation_view_is_a_whitelist_and_no_token_or_password_reaches_a_response_the_audit_or_the_logs(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['leak_token'] = true; // a faulty OptiNexus that returns the token
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });

        $created = $this->invite($token)->assertCreated();
        $id = $created->json('data.id');
        $secret = $this->state($tenant)['invitations'][$id]['token'];
        $this->assertSame(64, strlen($secret));

        $responses = [
            $created,
            $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($token))->assertOk(),
            $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertOk(),
            $this->postJson("/api/v1/app/delegation/invitations/{$id}/revoke", ['reason' => 'test'], $this->authHeaders($token))->assertOk(),
            $this->invite($token, ['email' => 'again@example.test'])->assertCreated(),
        ];
        $secret = $this->state($tenant)['invitations'][$id]['token']; // resend replaced it
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertStatus(409); // refused: revoked

        foreach ($responses as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString(hash('sha256', $secret), $body);
            $this->assertStringNotContainsStringIgnoringCase('"token', $body);
            $this->assertStringNotContainsStringIgnoringCase('password', $body);
        }
        $this->assertSame(['id', 'email', 'name', 'status', 'locale', 'role_ids', 'roles', 'invited_by', 'created_at', 'expires_at', 'last_sent_at', 'send_count', 'delivery', 'accepted_at', 'revoked_at', 'can_resend', 'can_revoke'], array_keys($created->json('data')));

        $audit = json_encode(AuditLog::query()->where('tenant_id', $tenant->id)->get()->map(fn ($a) => [$a->new_values, $a->old_values])->all());
        $this->assertStringNotContainsString($secret, $audit);
        $this->assertStringNotContainsStringIgnoringCase('token', $audit);
        $this->assertStringNotContainsStringIgnoringCase('password', $audit);
        $this->assertNotSame([], $logged, 'the refused resend was logged (code and ids only)');
        $this->assertStringNotContainsString($secret, implode("\n", $logged));
        $this->assertStringNotContainsString('@example.test', implode("\n", $logged), 'e-mail addresses are not logged');
    }

    // ------------------------------------------------------------- resend

    public function test_resend_gives_a_new_link_to_a_pending_or_expired_invitation_and_is_audited(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $id = $this->invite($token)->assertCreated()->json('data.id');
        $old = $this->state($tenant)['invitations'][$id]['token'];

        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertOk()->assertJsonPath('data.send_count', 2)->assertJsonPath('data.status', 'PENDING');
        $this->assertNotSame($old, $this->state($tenant)['invitations'][$id]['token'], 'the old link stops working at once');

        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations'][$id]['expires_at'] = now()->subDay()->toIso8601String();
        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($token))->assertJsonPath('data.0.status', 'EXPIRED');
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertOk()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.send_count', 3);

        $this->assertSame(2, AuditLog::query()->where('action', 'delegation.invitation_resent')->where('resource_id', $id)->count());
    }

    public function test_resend_refusals_cooldown_with_the_wait_limit_and_not_pending(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        Sleep::fake();
        $id = $this->invite($token)->assertCreated()->json('data.id');

        // Cooldown: 429 with the wait OptiNexus asks for, passed on as header and body; OptiFleet does not sleep and does not retry.
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invite_cooldown'] = 60;
        $before = count($this->invitationCalls());
        $response = $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertStatus(429)
            ->assertJsonPath('code', 'INVITATION_RESEND_COOLDOWN')->assertJsonPath('retryable', true)->assertJsonPath('outcome_unknown', false)
            ->assertJsonPath('message', Messages::text('errors.delegation.invitationResendCooldown', [], 'en'));
        $wait = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(60, $wait);
        $response->assertJsonPath('retry_after_seconds', $wait);
        $this->assertSame($before + 1, count($this->invitationCalls()));
        Sleep::assertNeverSlept();
        $this->assertSame(0, AuditLog::query()->where('action', 'delegation.refused')->count(), 'a cooldown decided nothing');
        $this->assertSame(1, $this->state($tenant)['invitations'][$id]['send_count']);

        // Limit: 409.
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invite_cooldown'] = 0;
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invite_resend_limit'] = 1;
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'INVITATION_RESEND_LIMIT')
            ->assertJsonMissingPath('retry_after_seconds');
        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($token))->assertJsonPath('data.0.can_resend', false)->assertJsonPath('data.0.can_revoke', true);

        // Not pending: the status that blocks is passed on so the screen can say "accepted" or "revoked".
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invite_resend_limit'] = 5;
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations'][$id]['status'] = 'ACCEPTED';
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertStatus(409)
            ->assertJsonPath('code', 'INVITATION_NOT_PENDING')->assertJsonPath('details.status', 'ACCEPTED');
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations'][$id]['status'] = 'REVOKED';
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/resend", [], $this->authHeaders($token))->assertStatus(409)->assertJsonPath('details.status', 'REVOKED');
    }

    // ------------------------------------------------------------- revoke

    public function test_revoke_is_idempotent_forwards_the_reason_and_cannot_undo_an_accepted_invitation(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $id = $this->invite($token)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/app/delegation/invitations/{$id}/revoke", ['reason' => 'Invited by mistake'], $this->authHeaders($token))->assertOk()
            ->assertJsonPath('data.status', 'REVOKED')->assertJsonPath('data.can_resend', false)->assertJsonPath('data.can_revoke', false);
        $this->assertSame('Invited by mistake', $this->state($tenant)['invitations'][$id]['revoke_reason']);
        $this->assertSame('Invited by mistake', collect($this->invitationCalls())->last()['body']['reason']);
        $this->postJson("/api/v1/app/delegation/invitations/{$id}/revoke", [], $this->authHeaders($token))->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->assertArrayNotHasKey('reason', collect($this->invitationCalls())->last()['body'], 'no reason, no field');

        $other = $this->invite($token, ['email' => 'other@example.test'])->assertCreated()->json('data.id');
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations'][$other]['status'] = 'ACCEPTED';
        $this->postJson("/api/v1/app/delegation/invitations/{$other}/revoke", [], $this->authHeaders($token))->assertStatus(409)
            ->assertJsonPath('code', 'INVITATION_NOT_PENDING')->assertJsonPath('details.status', 'ACCEPTED');

        $this->postJson("/api/v1/app/delegation/invitations/{$id}/revoke", ['reason' => str_repeat('x', 501)], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame(2, AuditLog::query()->where('action', 'delegation.invitation_revoked')->where('tenant_id', $tenant->id)->count());
    }

    // -------------------------------------------------- tenant boundaries

    public function test_an_invitation_of_another_organization_cannot_be_resent_or_revoked(): void
    {
        [$tenantA, , $tokenA] = $this->adminTenant();
        [$tenantB, , $tokenB] = $this->adminTenant();
        $foreign = $this->invite($tokenB, ['email' => 'foreign@example.test'])->assertCreated()->json('data.id');
        $this->nexus->reset();

        // The path always carries the session's own OptiNexus tenant; a tenant named in the request is ignored.
        $this->postJson("/api/v1/app/delegation/invitations/{$foreign}/resend?tenant_id={$tenantB->id}", ['tenant_id' => $tenantB->id, 'nexus_tenant_id' => $tenantB->optinexus_tenant_id], $this->authHeaders($tokenA))
            ->assertNotFound()->assertJsonPath('code', 'INVITATION_NOT_FOUND');
        $this->postJson("/api/v1/app/delegation/invitations/{$foreign}/revoke", ['tenant_id' => $tenantB->id], $this->authHeaders($tokenA))->assertNotFound()->assertJsonPath('code', 'INVITATION_NOT_FOUND');

        foreach ($this->invitationCalls() as $call) {
            $this->assertStringContainsString('/delegation/tenants/'.$tenantA->optinexus_tenant_id.'/', $call['path']);
            $this->assertStringNotContainsString($tenantB->optinexus_tenant_id, $call['path']);
        }
        $this->assertCount(2, $this->invitationCalls());
        $this->assertSame('PENDING', $this->state($tenantB)['invitations'][$foreign]['status']);
        $this->assertSame(1, $this->state($tenantB)['invitations'][$foreign]['send_count']);

        $this->assertSame([], $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($tokenA))->assertOk()->json('data'), 'the other organization\'s invitation is not listed');
        $this->assertSame(0, AuditLog::query()->where('tenant_id', $tenantB->id)->where('action', 'like', 'delegation.invitation_%')->where('action', '!=', 'delegation.invitation_created')->count());
    }

    public function test_an_id_that_is_not_a_uuid_never_leaves_the_application(): void
    {
        [, , $token] = $this->adminTenant();

        $this->postJson('/api/v1/app/delegation/invitations/1234/resend', [], $this->authHeaders($token))->assertNotFound();
        $this->postJson('/api/v1/app/delegation/invitations/not-a-uuid/revoke', [], $this->authHeaders($token))->assertNotFound();
        $this->assertSame([], $this->invitationCalls());
    }

    // ------------------------------------------------------------ outages

    public function test_a_lost_connection_while_inviting_is_never_retried_and_says_the_outcome_is_unknown(): void
    {
        [, , $token] = $this->adminTenant();
        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($token))->assertOk(); // warms the access answer and the service token
        $this->nexus->reset();
        $this->nexus->mode = 'down';

        $this->invite($token)->assertStatus(503)->assertJsonPath('code', 'DELEGATION_UNAVAILABLE')->assertJsonPath('outcome_unknown', true)->assertJsonPath('retryable', true)
            ->assertJsonPath('message', Messages::text('errors.delegation.unavailableChange', [], 'en'));
        $this->assertCount(1, $this->invitationCalls(), 'a change is not sent twice');

        $this->nexus->reset();
        $this->getJson('/api/v1/app/delegation/invitations', $this->authHeaders($token))->assertStatus(503)->assertJsonPath('outcome_unknown', false);
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'delegation.%')->where('action', '!=', 'delegation.invitation_created')->count());
    }

    // ------------------------------------------------------------ messages

    public function test_every_invitation_code_has_a_status_and_a_message_in_both_languages(): void
    {
        $codes = ['INVITATION_PENDING' => 409, 'ALREADY_MEMBER' => 409, 'ROLE_NOT_ASSIGNABLE' => 422, 'INVITATION_NOT_FOUND' => 404, 'INVITATION_NOT_PENDING' => 409,
            'INVITATION_RESEND_COOLDOWN' => 429, 'INVITATION_RESEND_LIMIT' => 409, 'INVITATION_RATE_LIMITED' => 429, 'DIRECT_USER_CREATION_DISABLED' => 410];

        foreach ($codes as $code => $status) {
            $this->assertArrayHasKey($code, DelegationErrors::MAP, "{$code} is not mapped");
            $e = DelegationErrors::fromResponse(['status' => $status, 'error' => ['code' => $code, 'message' => 'x', 'details' => null], 'correlation_id' => 'c-1', 'retry_after' => 7], true);
            $this->assertSame($status, $e->httpStatus, $code);
            $this->assertSame($status === 429 ? 7 : null, $e->retryAfterSeconds, $code);
            foreach (['en', 'id'] as $locale) {
                $this->assertNotSame($e->messageKey, Messages::text($e->messageKey, [], $locale), "{$code} has no {$locale} text");
            }
            $this->assertNotSame(Messages::text($e->messageKey, [], 'en'), Messages::text($e->messageKey, [], 'id'), "{$code} is not translated");
        }
        $this->assertContains('errors.delegation.invitationPending', DelegationErrors::messageKeys());
    }

    // ------------------------------------------------------------ compatibility

    public function test_the_deprecated_add_user_route_still_works_and_reports_when_the_deployment_switched_it_off(): void
    {
        [$tenant, , $token] = $this->adminTenant();

        $this->postJson('/api/v1/app/delegation/users', ['email' => 'legacy@example.test', 'name' => 'Legacy'], $this->authHeaders($token))->assertCreated()->assertJsonPath('data.outcome', 'created');

        $this->nexus->delegation[$tenant->optinexus_tenant_id]['direct_users_disabled'] = true;
        $this->postJson('/api/v1/app/delegation/users', ['email' => 'second@example.test', 'name' => 'Second'], $this->authHeaders($token))->assertStatus(410)
            ->assertJsonPath('code', 'DIRECT_USER_CREATION_DISABLED')->assertJsonPath('message', Messages::text('errors.delegation.directCreationDisabled', [], 'en'));
    }
}
