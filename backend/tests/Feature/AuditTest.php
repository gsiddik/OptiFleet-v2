<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use Tests\TestCase;

class AuditTest extends TestCase
{
    public function test_branch_creation_is_audited(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['branch.create', 'branch.view', 'audit.view']);

        $this->postJson('/api/v1/app/branches', [
            'code' => 'AUD-1',
            'name' => 'Audited Branch',
        ], $this->authHeaders($token))->assertStatus(201);

        $log = AuditLog::query()->where('resource_type', 'Branch')->where('action', 'created')->first();
        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('AUD-1', $log->new_values['code']);
    }

    public function test_branch_update_is_audited_with_old_and_new_values(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['branch.create', 'branch.update', 'branch.view']);

        $create = $this->postJson('/api/v1/app/branches', [
            'code' => 'AUD-2',
            'name' => 'Before Name',
        ], $this->authHeaders($token));
        $branchId = $create->json('data.id');

        $this->putJson("/api/v1/app/branches/{$branchId}", ['name' => 'After Name'], $this->authHeaders($token))->assertOk();

        $log = AuditLog::query()->where('resource_type', 'Branch')->where('resource_id', $branchId)->where('action', 'updated')->first();
        $this->assertNotNull($log);
        $this->assertSame('Before Name', $log->old_values['name']);
        $this->assertSame('After Name', $log->new_values['name']);
    }

    public function test_audit_log_is_visible_via_tenant_audit_endpoint(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['branch.create', 'audit.view']);

        $this->postJson('/api/v1/app/branches', ['code' => 'AUD-3', 'name' => 'Audited'], $this->authHeaders($token));

        $response = $this->getJson('/api/v1/app/audit-logs', $this->authHeaders($token));
        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_audit_log_isolated_per_tenant(): void
    {
        $tenantA = $this->makeTenant(['code' => 'AUD-A']);
        $tenantB = $this->makeTenant(['code' => 'AUD-B']);
        $this->grantModule($tenantA, 'ORGANIZATION');
        $this->grantModule($tenantB, 'ORGANIZATION');

        [, $tokenA] = $this->makeTenantUser($tenantA, ['branch.create']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['audit.view']);

        $this->postJson('/api/v1/app/branches', ['code' => 'AUD-ISO', 'name' => 'A only'], $this->authHeaders($tokenA));

        $response = $this->getJson('/api/v1/app/audit-logs', $this->authHeaders($tokenB));
        $response->assertOk();
        $resourceIds = collect($response->json('data'))->pluck('resource_type');
        $this->assertFalse(collect($response->json('data'))->contains(fn ($l) => ($l['action'] ?? null) === 'created' && str_contains(json_encode($l['new_values'] ?? []), 'AUD-ISO')));
    }
}
