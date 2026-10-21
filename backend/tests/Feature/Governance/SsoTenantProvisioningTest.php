<?php

namespace Tests\Feature\Governance;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Integration\Optinexus\SsoException;
use App\Domain\Integration\Optinexus\SsoLoginService;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

class SsoTenantProvisioningTest extends TestCase
{
    use InteractsWithCentralGovernance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    private function newCustomer(): array
    {
        $claims = [
            'tenant_id' => (string) Str::uuid(), 'sub' => (string) Str::uuid(),
            'email' => 'new.customer@example.test', 'email_verified' => true,
            'name' => 'New Customer',
        ];
        $this->nexus->tenant($claims['tenant_id'], ['optifleet.vehicle']);
        $this->nexus->grant($claims['tenant_id'], $claims['sub'], ['vehicle.view']);

        return $claims;
    }

    private function refused(array $claims, string $reason): void
    {
        try {
            app(SsoLoginService::class)->ticketFor($claims);
            $this->fail('Expected sign-in to be refused');
        } catch (SsoException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
        $this->assertSame(0, Tenant::withTrashed()->where('optinexus_tenant_id', $claims['tenant_id'])->count());
        $this->assertDatabaseMissing('users', ['email' => $claims['email']]);
    }

    public function test_first_login_creates_a_separate_customer_and_cannot_read_the_old_customers_vehicle(): void
    {
        $old = $this->makeCentralTenant();
        $oldAttributes = $old->getAttributes();
        [$oldUser] = $this->makeTenantUser($old);
        $vehicle = $this->makeVehicle($old, $this->makeBranch($old), $this->makeVehicleCategory());
        $claims = $this->newCustomer();

        $ticket = app(SsoLoginService::class)->ticketFor($claims);
        $new = Tenant::query()->where('optinexus_tenant_id', $claims['tenant_id'])->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('ACTIVE', $new->status);
        $this->assertSame('CENTRAL', $new->governance_mode);
        $this->assertSame('ACME', $new->code);
        $this->assertSame('Acme', $new->name);
        $this->assertSame($oldAttributes, $old->refresh()->getAttributes());
        $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $old->id, 'user_id' => User::where('email', $claims['email'])->firstOrFail()->id]);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $old->id, 'user_id' => $oldUser->id]);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'Tenant', 'resource_id' => $new->id, 'action' => 'provisioned_from_optinexus']);

        $response = $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $ticket])->assertOk();
        $headers = $this->authHeaders($response->json('data.token'));
        $this->getJson('/api/v1/app/vehicles', $headers)->assertOk()->assertJsonMissing(['id' => $vehicle->id]);
        $this->getJson('/api/v1/app/vehicles/'.$vehicle->id, $headers)->assertNotFound();
        $this->getJson('/api/v1/auth/me', $headers)->assertOk()->assertJsonPath('data.permissions', ['vehicle.view']);
    }

    public function test_repeated_sign_in_is_idempotent(): void
    {
        $claims = $this->newCustomer();
        app(SsoLoginService::class)->ticketFor($claims);
        app(SsoLoginService::class)->ticketFor($claims);
        $this->assertSame(1, Tenant::query()->where('optinexus_tenant_id', $claims['tenant_id'])->count());
        $this->assertSame(1, User::query()->where('optinexus_subject', $claims['sub'])->count());
        $this->assertSame(1, TenantUser::query()->count());
    }

    public function test_matching_code_does_not_relink_another_customer_even_when_soft_deleted(): void
    {
        $old = $this->makeTenant(['code' => 'ACME', 'optinexus_tenant_id' => (string) Str::uuid()]);
        $old->delete();
        $claims = $this->newCustomer();
        app(SsoLoginService::class)->ticketFor($claims);
        $new = Tenant::query()->where('optinexus_tenant_id', $claims['tenant_id'])->firstOrFail();
        $this->assertSame('NEXUS-'.$claims['tenant_id'], $new->code);
        $this->assertNotSame($old->id, $new->id);
        $this->assertTrue($old->refresh()->trashed());
    }

    public function test_claim_display_names_are_not_used_to_match_or_name_a_customer(): void
    {
        $claims = $this->newCustomer() + ['tenant_code' => 'UNTRUSTED', 'tenant_name' => 'Not the verified name'];
        app(SsoLoginService::class)->ticketFor($claims);
        $this->assertDatabaseHas('tenants', ['optinexus_tenant_id' => $claims['tenant_id'], 'code' => 'ACME', 'name' => 'Acme']);
    }

    public function test_operator_can_disable_provisioning(): void
    {
        config(['optinexus.sso.provision_tenants' => false]);
        $claims = $this->newCustomer();
        $this->refused($claims, 'tenant_not_linked');
        Http::assertNothingSent();
    }

    public function test_unverified_email_creates_no_tenant(): void
    {
        $claims = $this->newCustomer();
        $claims['email_verified'] = false;
        $this->refused($claims, 'email_not_verified');
        Http::assertNothingSent();
    }

    public function test_application_access_revoked_since_token_issue_creates_nothing(): void
    {
        $claims = $this->newCustomer();
        $this->nexus->withdraw($claims['tenant_id'], $claims['sub']);
        $this->refused($claims, 'access_denied');
    }

    public function test_inactive_nexus_tenant_creates_nothing(): void
    {
        $claims = $this->newCustomer();
        $this->nexus->tenant($claims['tenant_id'], [], 'SUSPENDED');
        $this->refused($claims, 'access_denied');
    }

    public function test_an_entry_subscription_alone_does_not_provision_fleet(): void
    {
        $claims = $this->newCustomer();
        $this->nexus->tenant($claims['tenant_id'], [], 'ACTIVE', ['optiaccounting']);
        $this->refused($claims, 'tenant_not_entitled');
    }

    public function test_provider_outage_or_unbound_service_account_leaves_no_projection(): void
    {
        foreach (['down', 'unbound', 'tenant_mismatch'] as $mode) {
            $claims = $this->newCustomer();
            $this->nexus->mode = $mode;
            try {
                app(SsoLoginService::class)->ticketFor($claims);
                $this->fail('Expected a provider failure');
            } catch (\App\Domain\Governance\Exceptions\GovernanceUnavailableException|\App\Domain\Governance\Exceptions\GovernanceContractException $e) {
                $this->assertDatabaseMissing('tenants', ['optinexus_tenant_id' => $claims['tenant_id']]);
                $this->assertDatabaseMissing('users', ['email' => $claims['email']]);
            }
        }
    }

    public function test_conflicting_identity_rolls_back_the_new_tenant_too(): void
    {
        $old = $this->makeCentralTenant();
        [$user] = $this->makeTenantUser($old);
        $user->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $claims = $this->newCustomer();
        $claims['email'] = $user->email;
        try {
            app(SsoLoginService::class)->ticketFor($claims);
            $this->fail('Expected account_conflict');
        } catch (SsoException $e) {
            $this->assertSame('account_conflict', $e->getMessage());
        }
        $this->assertDatabaseMissing('tenants', ['optinexus_tenant_id' => $claims['tenant_id']]);
        $this->assertSame(1, TenantUser::query()->count());
    }

    public function test_locally_disabled_or_deleted_tenants_are_never_recreated(): void
    {
        foreach (['INACTIVE', 'SUSPENDED', 'DELETED'] as $status) {
            $claims = $this->newCustomer();
            $tenant = $this->makeTenant(['optinexus_tenant_id' => $claims['tenant_id'], 'status' => $status === 'DELETED' ? 'ACTIVE' : $status]);
            if ($status === 'DELETED') {
                $tenant->delete();
            }
            try {
                app(SsoLoginService::class)->ticketFor($claims);
                $this->fail('Expected tenant_inactive');
            } catch (SsoException $e) {
                $this->assertSame('tenant_inactive', $e->getMessage());
            }
            $this->assertSame(1, Tenant::withTrashed()->where('optinexus_tenant_id', $claims['tenant_id'])->count());
            $this->assertDatabaseMissing('users', ['email' => $claims['email']]);
        }
        Http::assertNothingSent();
    }

    public function test_missing_tenant_id_never_adopts_an_unlinked_local_tenant(): void
    {
        $this->makeTenant();
        $claims = $this->newCustomer();
        $claims['tenant_id'] = null;
        $this->expectException(SsoException::class);
        $this->expectExceptionMessage('access_denied');
        app(SsoLoginService::class)->ticketFor($claims);
    }
}
