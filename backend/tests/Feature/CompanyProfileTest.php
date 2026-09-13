<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — G-15: Tenant previously had no company-profile data (tax ID,
 * address, contact info) and no self-service endpoint to view or edit it.
 */
class CompanyProfileTest extends TestCase
{
    public function test_tenant_can_view_and_update_its_own_company_profile(): void
    {
        $tenant = $this->makeTenant(['code' => 'CO-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['company.view', 'company.update']);
        $headers = $this->authHeaders($token);

        $this->getJson('/api/v1/app/account/company', $headers)->assertOk()->assertJsonPath('data.id', $tenant->id);

        $update = $this->putJson('/api/v1/app/account/company', [
            'legal_name' => 'PT Test Fleet Indonesia',
            'tax_id' => '01.234.567.8-901.000',
            'address' => 'Jl. Contoh No. 1, Jakarta',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'phone' => '+62-21-5551234',
            'fax' => '+62-21-5551235',
            'email' => 'ops@testfleet.example',
            'website' => 'https://testfleet.example',
        ], $headers)->assertOk();

        $this->assertSame('PT Test Fleet Indonesia', $update->json('data.legal_name'));
        $this->assertSame('01.234.567.8-901.000', $update->json('data.tax_id'));
        $this->assertSame('DKI Jakarta', $update->json('data.province'));
        $this->assertSame('Jakarta Selatan', $update->json('data.city'));
        $this->assertSame('+62-21-5551235', $update->json('data.fax'));
        $this->assertSame($tenant->id, $update->json('data.id'));
    }

    public function test_company_profile_denied_without_permission(): void
    {
        $tenant = $this->makeTenant(['code' => 'COB-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, []);

        $this->getJson('/api/v1/app/account/company', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_company_profile_is_scoped_to_the_authenticated_tenant(): void
    {
        $tenantA = $this->makeTenant(['code' => 'COC-'.Str::random(4), 'legal_name' => 'Tenant A Legal Name']);
        $tenantB = $this->makeTenant(['code' => 'COD-'.Str::random(4), 'legal_name' => 'Tenant B Legal Name']);
        [, $tokenA] = $this->makeTenantUser($tenantA, ['company.view']);

        $response = $this->getJson('/api/v1/app/account/company', $this->authHeaders($tokenA))->assertOk();
        $this->assertSame('Tenant A Legal Name', $response->json('data.legal_name'));
        $this->assertNotSame($tenantB->id, $response->json('data.id'));
    }
}
