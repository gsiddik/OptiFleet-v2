<?php

namespace Tests\Concerns;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\FakeNexus;

/**
 * Sets up a centrally governed tenant (audit A02/R05) against a FakeNexus built from the documented contract.
 */
trait InteractsWithCentralGovernance
{
    protected FakeNexus $nexus;

    protected function bootCentralGovernance(): void
    {
        Cache::flush();
        app(CentralGovernance::class)->flushMemo();
        $this->nexus = (new FakeNexus)->install();
    }

    /** A tenant linked to OptiNexus and governed by it. $capabilities are the module capabilities it is entitled to. */
    protected function makeCentralTenant(array $capabilities = ['optifleet.vehicle'], array $attributes = []): Tenant
    {
        $nexusTenantId = (string) Str::uuid();
        $this->nexus->tenant($nexusTenantId, $capabilities);

        $tenant = $this->makeTenant($attributes + ['optinexus_tenant_id' => $nexusTenantId]);
        $tenant->forceFill(['governance_mode' => 'CENTRAL'])->save();

        return $tenant->refresh();
    }

    /**
     * A member of a centrally governed tenant with an OptiNexus session. $permissions are granted by (the fake) OptiNexus;
     * $localRoleNames, if given, are local roles that can only narrow.
     *
     * @return array{0: User, 1: string}
     */
    protected function makeCentralUser(Tenant $tenant, array $permissions, ?array $localRolePermissions = null): array
    {
        [$user] = $this->makeTenantUser($tenant, $localRolePermissions ?? []);
        $user->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $this->nexus->grant($tenant->optinexus_tenant_id, $user->optinexus_subject, $permissions);

        return [$user, $this->ssoToken($user, $tenant)];
    }

    protected function ssoToken(User $user, Tenant $tenant): string
    {
        return $user->createToken('test', ['tenant:'.$tenant->id, 'sso'])->plainTextToken;
    }

    protected function membership(User $user, Tenant $tenant): TenantUser
    {
        return TenantUser::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->firstOrFail();
    }

    protected function forgetGovernance(): void
    {
        Cache::flush();
        app(CentralGovernance::class)->flushMemo();
    }
}
