<?php

namespace Tests\Feature\Governance;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Governance\GovernanceModeService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

/**
 * Switching a tenant between LOCAL (legacy) and CENTRAL governance: only through an explicit, audited operator action after a
 * readiness check; nothing is migrated automatically (audit A02/R05).
 */
class GovernanceModeTest extends TestCase
{
    use InteractsWithCentralGovernance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
        config(['optinexus.sso.client_id' => 'fleet-sso', 'optinexus.sso.redirect_uri' => 'https://fleet.test/cb']);
    }

    /** A legacy (LOCAL) tenant that is linked to an OptiNexus tenant. */
    private function legacyTenant(array $capabilities = ['optifleet.vehicle']): Tenant
    {
        $nexusTenantId = (string) Str::uuid();
        $this->nexus->tenant($nexusTenantId, $capabilities);

        return $this->makeTenant(['optinexus_tenant_id' => $nexusTenantId]);
    }

    private function operator(): string
    {
        [$platform] = $this->makePlatformUser();

        return $platform->email;
    }

    public function test_every_new_or_existing_tenant_is_local_until_an_operator_enables_central(): void
    {
        $tenant = $this->makeTenant();

        $this->assertSame('LOCAL', $tenant->refresh()->governance_mode);
        $this->artisan('optinexus:governance:inventory')->assertSuccessful();
    }

    public function test_the_database_refuses_central_without_a_link(): void
    {
        $tenant = $this->makeTenant();

        $this->expectException(QueryException::class);
        $tenant->forceFill(['governance_mode' => 'CENTRAL'])->save();
    }

    public function test_inventory_reports_linked_and_local_only_members_without_changing_anything(): void
    {
        $tenant = $this->legacyTenant();
        [$linked] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $linked->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $this->makeTenantUser($tenant, ['vehicle.view']);
        $this->makeTenantUser($tenant);

        $this->artisan('optinexus:governance:inventory', ['--tenant' => $tenant->code, '--json' => true])->assertSuccessful();
        $inventory = app(GovernanceModeService::class)->inventory($tenant);

        $this->assertSame(3, $inventory['members_active']);
        $this->assertSame(1, $inventory['members_linked']);
        $this->assertSame(2, $inventory['members_unlinked']);
        $this->assertSame(2, $inventory['local_role_assignments']);
        $this->assertSame('LOCAL', $tenant->refresh()->governance_mode);
        $this->assertSame(0, $this->nexus->callsTo('effective-permissions'), 'the inventory is local only');
    }

    public function test_check_blocks_a_tenant_that_is_not_linked_and_changes_nothing(): void
    {
        $tenant = $this->makeTenant();

        $this->artisan('optinexus:governance:enable', ['tenant' => $tenant->code, '--check' => true])->assertFailed();
        $this->assertSame('LOCAL', $tenant->refresh()->governance_mode);
    }

    public function test_check_blocks_when_optinexus_cannot_be_asked_or_would_lock_everybody_out(): void
    {
        $tenant = $this->legacyTenant();
        [$user] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $subject = (string) Str::uuid();
        $user->forceFill(['optinexus_subject' => $subject])->save();

        // OptiNexus grants the linked member nothing: switching now would lock everybody out.
        $this->nexus->withdraw($tenant->optinexus_tenant_id, $subject);
        $this->artisan('optinexus:governance:enable', ['tenant' => $tenant->code, '--check' => true])->assertFailed();

        // Granted: ready (with warnings for the local-only members).
        $this->nexus->grant($tenant->optinexus_tenant_id, $subject, ['vehicle.view']);
        $this->artisan('optinexus:governance:enable', ['tenant' => $tenant->code, '--check' => true])->assertSuccessful();
        $this->assertSame('LOCAL', $tenant->refresh()->governance_mode);
    }

    public function test_enabling_needs_a_named_platform_operator_and_is_audited(): void
    {
        $tenant = $this->legacyTenant();
        [$user] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $subject = (string) Str::uuid();
        $user->forceFill(['optinexus_subject' => $subject])->save();
        $this->nexus->grant($tenant->optinexus_tenant_id, $subject, ['vehicle.view']);

        $this->artisan('optinexus:governance:enable', ['tenant' => $tenant->code])->assertFailed();
        $this->assertSame('LOCAL', $tenant->refresh()->governance_mode);

        $this->artisan('optinexus:governance:enable', ['tenant' => $tenant->code, '--by' => $user->email])->assertFailed(); // not a platform user
        $this->assertSame('LOCAL', $tenant->refresh()->governance_mode);

        $this->artisan('optinexus:governance:enable', ['tenant' => $tenant->code, '--by' => $this->operator()])->assertSuccessful();
        $tenant->refresh();
        $this->assertSame('CENTRAL', $tenant->governance_mode);
        $this->assertNotNull($tenant->governance_enabled_at);
        $this->assertNotNull($tenant->governance_enabled_by);

        $audit = AuditLog::query()->where('resource_id', $tenant->id)->where('action', 'governance.enabled')->first();
        $this->assertNotNull($audit);
        $this->assertSame('CENTRAL', $audit->new_values['governance_mode']);
        $this->assertSame($tenant->governance_enabled_by, $audit->new_values['operator_id']);
    }

    public function test_enabling_revokes_password_sessions_and_keeps_local_grants_as_restrictions_only(): void
    {
        $tenant = $this->legacyTenant();
        [$user, $token] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $password = $user->createToken('password-login', ['tenant:'.$tenant->id])->plainTextToken;
        $subject = (string) Str::uuid();
        $user->forceFill(['optinexus_subject' => $subject])->save();
        $this->nexus->grant($tenant->optinexus_tenant_id, $subject, ['vehicle.view', 'vehicle.create']);

        $this->artisan('optinexus:governance:enable', ['tenant' => $tenant->code, '--by' => $this->operator()])->assertSuccessful();

        $this->assertNull(PersonalAccessToken::findToken($password), 'a password session cannot enter a central tenant');
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($password))->assertUnauthorized();

        // The SSO session works, and the local role (vehicle.view only) narrows OptiNexus' vehicle.view + vehicle.create.
        $sso = $this->ssoToken($user, $tenant);
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($sso))->assertOk();
        $this->postJson('/api/v1/app/vehicles', [], $this->authHeaders($sso))->assertForbidden();
    }

    public function test_break_glass_disable_needs_operator_reason_and_confirmation_and_is_audited(): void
    {
        $tenant = $this->makeCentralTenant();

        $this->artisan('optinexus:governance:disable', ['tenant' => $tenant->code, '--by' => $this->operator(), '--reason' => 'short'])->assertFailed();
        $this->assertSame('CENTRAL', $tenant->refresh()->governance_mode);

        $this->artisan('optinexus:governance:disable', [
            'tenant' => $tenant->code, '--by' => $this->operator(), '--reason' => 'OptiNexus outage INC-1234 longer than one day', '--confirm' => true,
        ])->assertSuccessful();

        $this->assertSame('LOCAL', $tenant->refresh()->governance_mode);
        $audit = AuditLog::query()->where('resource_id', $tenant->id)->where('action', 'governance.disabled')->first();
        $this->assertNotNull($audit);
        $this->assertTrue($audit->new_values['break_glass']);
        $this->assertStringContainsString('INC-1234', $audit->new_values['reason']);
    }
}
