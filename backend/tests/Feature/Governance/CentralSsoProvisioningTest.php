<?php

namespace Tests\Feature\Governance;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Integration\Optinexus\SsoException;
use App\Domain\Integration\Optinexus\SsoLoginService;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

/**
 * A person an Admin Tenant created in OptiNexus (through the delegation API) signs in to OptiFleet for the first time:
 * in a centrally governed tenant OptiFleet makes the local projection itself (R05/R08); in a legacy tenant it still refuses.
 */
class CentralSsoProvisioningTest extends TestCase
{
    use InteractsWithCentralGovernance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    private function claims($tenant, array $overrides = []): array
    {
        $claims = array_merge([
            'sub' => (string) Str::uuid(), 'email' => 'new.person@example.test', 'email_verified' => true, 'name' => 'New Person',
            'tenant_id' => $tenant->optinexus_tenant_id, 'apps' => [['code' => 'optifleet', 'name' => 'OptiFleet', 'launch_url' => 'http://fleet.test']],
        ], $overrides);
        $this->nexus->grant($tenant->optinexus_tenant_id, $claims['sub'], ['vehicle.view']);

        return $claims;
    }

    public function test_first_sign_in_creates_a_projection_without_a_grant_or_a_usable_password(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        $claims = $this->claims($tenant);
        $this->nexus->grant($tenant->optinexus_tenant_id, $claims['sub'], ['vehicle.view']);

        $ticket = app(SsoLoginService::class)->ticketFor($claims);
        $res = $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $ticket])->assertOk();

        $user = User::query()->where('optinexus_subject', $claims['sub'])->firstOrFail();
        $this->assertSame('new.person@example.test', $user->email);
        $this->assertSame('tenant', $user->user_type);
        $this->assertSame(0, $user->roleAssignments()->count(), 'the projection carries no local grant');
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseHas('data_scope_assignments', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'scope_type' => 'TENANT']);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'User', 'resource_id' => $user->id, 'action' => 'provisioned_from_optinexus']);

        $token = $res->json('data.token');
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->postJson('/api/v1/app/vehicles', [], $this->authHeaders($token))->assertForbidden();
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertJsonPath('data.permissions.0', 'vehicle.view');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'anything-at-all'])->assertStatus(422);
    }

    public function test_second_sign_in_does_not_duplicate_anything(): void
    {
        $tenant = $this->makeCentralTenant();
        $claims = $this->claims($tenant);

        app(SsoLoginService::class)->ticketFor($claims);
        app(SsoLoginService::class)->ticketFor($claims);

        $this->assertSame(1, User::query()->where('email', 'new.person@example.test')->count());
        $this->assertSame(1, TenantUser::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, DataScopeAssignment::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_an_existing_local_account_is_linked_once_not_duplicated(): void
    {
        $tenant = $this->makeCentralTenant();
        [$existing] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $claims = $this->claims($tenant, ['email' => strtoupper($existing->email)]);

        app(SsoLoginService::class)->ticketFor($claims);

        $this->assertSame($claims['sub'], $existing->refresh()->optinexus_subject);
        $this->assertSame(1, User::query()->whereRaw('lower(email) = ?', [strtolower($existing->email)])->count());
    }

    public function test_an_account_bound_to_another_identity_is_never_rebound(): void
    {
        $tenant = $this->makeCentralTenant();
        [$existing] = $this->makeTenantUser($tenant, []);
        $existing->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();

        $this->expectException(SsoException::class);
        $this->expectExceptionMessage('account_conflict');
        app(SsoLoginService::class)->ticketFor($this->claims($tenant, ['email' => $existing->email]));
    }

    public function test_an_unverified_email_creates_nothing(): void
    {
        $tenant = $this->makeCentralTenant();
        try {
            app(SsoLoginService::class)->ticketFor($this->claims($tenant, ['email_verified' => false]));
            $this->fail('expected a refusal');
        } catch (SsoException $e) {
            $this->assertSame('email_not_verified', $e->getMessage());
        }
        $this->assertSame(0, User::query()->where('email', 'new.person@example.test')->count());
    }

    public function test_a_legacy_tenant_still_does_not_create_accounts(): void
    {
        $tenant = $this->makeTenant(['optinexus_tenant_id' => (string) Str::uuid()]);
        $this->expectException(SsoException::class);
        $this->expectExceptionMessage('user_not_provisioned');
        app(SsoLoginService::class)->ticketFor($this->claims($tenant));
    }

    public function test_a_membership_an_administrator_switched_off_stays_off(): void
    {
        $tenant = $this->makeCentralTenant();
        [$user] = $this->makeTenantUser($tenant, []);
        $user->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $this->membership($user, $tenant)->update(['status' => 'inactive']);

        try {
            app(SsoLoginService::class)->ticketFor($this->claims($tenant, ['sub' => $user->optinexus_subject, 'email' => $user->email]));
            $this->fail('expected a refusal');
        } catch (SsoException $e) {
            $this->assertSame('no_membership', $e->getMessage());
        }
        $this->assertSame('inactive', $this->membership($user, $tenant)->status);
    }

    public function test_existing_members_keep_their_data_scopes(): void
    {
        $tenant = $this->makeCentralTenant();
        $branch = $this->makeBranch($tenant);
        [$user] = $this->makeTenantUser($tenant, [], ['BRANCH' => $branch->id]);
        $user->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();

        app(SsoLoginService::class)->ticketFor($this->claims($tenant, ['sub' => $user->optinexus_subject, 'email' => $user->email]));

        $scopes = DataScopeAssignment::query()->where('user_id', $user->id)->pluck('scope_type')->all();
        $this->assertSame(['BRANCH'], $scopes, 'a restriction an administrator set is not widened by signing in');
    }

    public function test_the_exchange_refuses_when_optinexus_withdrew_access_in_between_and_issues_no_token(): void
    {
        $tenant = $this->makeCentralTenant();
        $claims = $this->claims($tenant);
        $ticket = app(SsoLoginService::class)->ticketFor($claims);
        $this->nexus->withdraw($tenant->optinexus_tenant_id, $claims['sub']);

        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $ticket])->assertForbidden();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_exchange_is_a_503_when_optinexus_cannot_be_asked(): void
    {
        $tenant = $this->makeCentralTenant();
        $ticket = app(SsoLoginService::class)->ticketFor($this->claims($tenant));
        $this->nexus->mode = 'down';

        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $ticket])->assertStatus(503)->assertJsonPath('code', 'GOVERNANCE_UNAVAILABLE');
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_verified_email_change_preserves_subject_identity_and_rejects_conflicts(): void
    {
        $tenant = $this->makeCentralTenant();
        $claims = $this->claims($tenant);
        $service = app(SsoLoginService::class);
        $service->ticketFor($claims);
        $user = User::where('optinexus_subject', $claims['sub'])->firstOrFail();
        $service->ticketFor([...$claims, 'email' => 'changed@example.test']);
        $this->assertSame('changed@example.test', $user->fresh()->email);
        $this->assertSame(1, User::where('optinexus_subject', $claims['sub'])->count());
        [$other] = $this->makeTenantUser($tenant, []);
        try {
            $service->ticketFor([...$claims, 'email' => $other->email]);
            $this->fail('Conflicting email must not be adopted.');
        } catch (SsoException $e) {
            $this->assertSame('account_conflict', $e->getMessage());
        }
        $this->assertSame('changed@example.test', $user->fresh()->email);
        $this->expectExceptionMessage('email_not_verified');
        $service->ticketFor([...$claims, 'email' => 'unverified@example.test', 'email_verified' => false]);
    }
}
