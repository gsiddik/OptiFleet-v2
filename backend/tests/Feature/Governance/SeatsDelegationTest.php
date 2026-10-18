<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Delegation\DelegationErrors;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\Support\Messages;
use App\Models\User;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\Support\FakeNexusDelegation;
use Tests\TestCase;

/**
 * Seats (OptiNexus contract GOVERNANCE_CONTRACTS.md section 2d), consumer side. OptiNexus alone counts seats and refuses an addition that
 * does not fit; OptiFleet shows the figures it was given and passes the refusals on, with nothing granted and no count of its own.
 * OptiFleet declares no `user_limit` entitlement, so a tenant is NOT_CONTROLLED until a test sets a state on the fake.
 */
class SeatsDelegationTest extends TestCase
{
    use InteractsWithCentralGovernance;

    private const ACCESS = ['user.view', 'user.create', 'user.assign', 'user.update', 'role.view', 'role.create', 'role.update', 'role.assign_permission'];

    private const DELEGABLE = [
        ['permission' => 'optifleet.vehicle.view', 'name' => 'Vehicle: View', 'description' => null, 'capability' => 'optifleet.vehicle'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function adminTenant(): array
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [$admin, $token] = $this->makeCentralUser($tenant, self::ACCESS);
        FakeNexusDelegation::seed($this->nexus, $tenant->optinexus_tenant_id, [$admin->optinexus_subject], self::DELEGABLE);
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['invite_cooldown'] = 0;

        return [$tenant, $admin, $token];
    }

    private function seat(Tenant $tenant, array $seat): void
    {
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['seat'] = $seat;
    }

    /** The Admin Tenant holds a seat in OptiNexus; the fake lists only the people it was told about. */
    private function hold(Tenant $tenant, string $email): void
    {
        $id = (string) Str::uuid();
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['identities'][$email] = $id;
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['users'][$id] = ['id' => $id, 'name' => 'Holder', 'email' => $email, 'status' => 'ACTIVE',
            'membership_status' => 'ACTIVE', 'is_tenant_admin' => false, 'application_access' => 'ACTIVE', 'roles' => []];
    }

    private function invite(string $token, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/app/delegation/invitations', $body + ['email' => 'New.Person@Example.test', 'name' => 'New Person'], $this->authHeaders($token));
    }

    private function add(string $token, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/app/delegation/users', $body + ['email' => 'direct@example.test', 'name' => 'Direct Person'], $this->authHeaders($token));
    }

    public function test_a_product_without_a_user_limit_reports_that_no_seat_limit_is_applied_and_still_accepts_people(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $this->hold($tenant, 'one@example.test');

        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertOk()
            ->assertJsonPath('seats.state', 'NOT_CONTROLLED')->assertJsonPath('seats.controlled', false)->assertJsonPath('seats.limit', null)
            ->assertJsonPath('seats.used', 1)->assertJsonPath('seats.available', null)->assertJsonPath('seats.can_add', true);
        $this->invite($token)->assertCreated();
        $this->add($token)->assertCreated();
    }

    public function test_the_lists_carry_the_figures_of_optinexus_used_reserved_available_never_negative(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $this->seat($tenant, ['state' => 'LIMITED', 'limit' => 3]);
        $this->hold($tenant, 'one@example.test');
        $this->invite($token, ['email' => 'pending@example.test'])->assertCreated();

        foreach (['users', 'invitations'] as $list) {
            $this->getJson("/api/v1/app/delegation/{$list}", $this->authHeaders($token))->assertOk()
                ->assertJsonPath('seats.state', 'LIMITED')->assertJsonPath('seats.limit', 3)->assertJsonPath('seats.used', 1)->assertJsonPath('seats.reserved', 1)
                ->assertJsonPath('seats.available', 1)->assertJsonPath('seats.over_limit', false)->assertJsonPath('seats.can_add', true)->assertJsonPath('meta.total', fn ($v) => is_int($v));
        }

        // The limit drops below what is used and reserved: nothing is removed, availability stays 0, the overshoot is its own figure.
        $this->seat($tenant, ['state' => 'LIMITED', 'limit' => 1]);
        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertOk()
            ->assertJsonPath('seats.available', 0)->assertJsonPath('seats.over_limit', true)->assertJsonPath('seats.over_by', 1)->assertJsonPath('seats.blocked_reason', 'SEAT_OVER_LIMIT');
    }

    public function test_the_last_seat_is_refused_by_optinexus_for_an_invitation_and_a_direct_addition_with_its_figures(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $this->seat($tenant, ['state' => 'LIMITED', 'limit' => 2]);
        $this->hold($tenant, 'one@example.test');
        $this->invite($token, ['email' => 'pending@example.test'])->assertCreated();

        $this->invite($token)->assertStatus(409)->assertJsonPath('code', 'SEAT_LIMIT_REACHED')
            ->assertJsonPath('details.limit', 2)->assertJsonPath('details.used', 1)->assertJsonPath('details.reserved', 1)->assertJsonPath('details.available', 0)
            ->assertJsonPath('details.recovery', 'FREE_SEAT_OR_RAISE_LIMIT')->assertJsonPath('retryable', false)->assertJsonPath('outcome_unknown', false)
            ->assertJsonPath('message', Messages::text('errors.delegation.seatLimitReached', [], 'en'));
        $this->add($token)->assertStatus(409)->assertJsonPath('code', 'SEAT_LIMIT_REACHED');

        // Nothing was created by the refused requests; the pending invitation is the only reservation.
        $this->assertCount(1, $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations']);
        $this->assertArrayNotHasKey('direct@example.test', $this->nexus->delegation[$tenant->optinexus_tenant_id]['identities']);
    }

    public function test_over_the_limit_is_its_own_refusal_and_nobody_is_removed(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $this->hold($tenant, 'one@example.test');
        $this->hold($tenant, 'two@example.test');
        $this->seat($tenant, ['state' => 'LIMITED', 'limit' => 1]);

        $this->invite($token)->assertStatus(409)->assertJsonPath('code', 'SEAT_OVER_LIMIT')->assertJsonPath('details.over_limit', true)
            ->assertJsonPath('details.recovery', 'REDUCE_USAGE_OR_RAISE_LIMIT')->assertJsonPath('message', Messages::text('errors.delegation.seatOverLimit', [], 'en'));
        $this->assertSame(2, count(array_filter($this->nexus->delegation[$tenant->optinexus_tenant_id]['users'], fn ($u) => $u['application_access'] === 'ACTIVE')));
    }

    public function test_an_undecided_limit_refuses_and_never_means_unlimited(): void
    {
        [$tenant, , $token] = $this->adminTenant();

        foreach (['ENTITLEMENT_MISSING' => 'SEAT_ENTITLEMENT_MISSING', 'ENTITLEMENT_INVALID' => 'SEAT_ENTITLEMENT_INVALID'] as $state => $code) {
            $this->seat($tenant, ['state' => $state]);
            $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertOk()->assertJsonPath('seats.can_add', false)->assertJsonPath('seats.blocked_reason', $code);
            $this->invite($token)->assertStatus(409)->assertJsonPath('code', $code)->assertJsonPath('details.recovery', 'SET_ENTITLEMENT')->assertJsonPath('retryable', false);
        }
        $this->assertSame([], $this->nexus->delegation[$tenant->optinexus_tenant_id]['invitations']);
    }

    public function test_the_two_transient_seat_answers_are_refusals_with_the_wait_not_an_outage_with_unknown_outcome(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        $this->seat($tenant, ['state' => 'ENTITLEMENT_UNAVAILABLE']);
        Sleep::fake();

        $response = $this->invite($token)->assertStatus(503)->assertJsonPath('code', 'SEAT_ENTITLEMENT_UNAVAILABLE')->assertJsonPath('retryable', true)
            ->assertJsonPath('outcome_unknown', false)->assertJsonPath('retry_after_seconds', 5)->assertHeader('Retry-After', '5');
        $response->assertJsonPath('message', Messages::text('errors.delegation.seatEntitlementUnavailable', [], 'en'));
        Sleep::assertNeverSlept();
    }

    public function test_every_seat_code_has_a_status_and_a_message_in_both_languages(): void
    {
        $codes = ['SEAT_LIMIT_REACHED' => 409, 'SEAT_OVER_LIMIT' => 409, 'SEAT_ENTITLEMENT_MISSING' => 409, 'SEAT_ENTITLEMENT_INVALID' => 409, 'SEAT_ENTITLEMENT_UNAVAILABLE' => 503, 'SEAT_CONTENTION' => 503];

        foreach ($codes as $code => $status) {
            $e = DelegationErrors::fromResponse(['status' => $status, 'error' => ['code' => $code, 'message' => 'x', 'details' => ['limit' => 2, 'token' => ['a', ['b']]]], 'correlation_id' => 'c-1', 'retry_after' => 7], true);
            $this->assertSame($status, $e->httpStatus, $code);
            $this->assertSame($status === 503 ? 7 : null, $e->retryAfterSeconds, $code);
            $this->assertSame(['limit' => 2], $e->details, 'only scalars and lists of scalars are passed on');
            foreach (['en', 'id'] as $locale) {
                $this->assertNotSame($e->messageKey, Messages::text($e->messageKey, [], $locale), "{$code} has no {$locale} text");
            }
            $this->assertNotSame(Messages::text($e->messageKey, [], 'en'), Messages::text($e->messageKey, [], 'id'), "{$code} is not translated");
        }
    }

    public function test_fleet_keeps_no_seat_counter_of_its_own(): void
    {
        $sources = shell_exec('grep -rIl "user_limit\|USER_LIMIT\|seat_limit" '.escapeshellarg(app_path()).' '.escapeshellarg(base_path('database')).' '.escapeshellarg(base_path('routes')).' 2>/dev/null') ?: '';

        $this->assertSame('', trim($sources), 'seats are counted by OptiNexus alone (contract 2d); OptiFleet only shows them');
    }
}
