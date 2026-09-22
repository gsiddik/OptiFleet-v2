<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'logo_url' => 'https://files.example/logo.png',
            'workshop_working_days' => 6,
        ], $headers)->assertOk();

        $this->assertSame('PT Test Fleet Indonesia', $update->json('data.legal_name'));
        $this->assertSame('01.234.567.8-901.000', $update->json('data.tax_id'));
        $this->assertSame('DKI Jakarta', $update->json('data.province'));
        $this->assertSame('Jakarta Selatan', $update->json('data.city'));
        $this->assertSame('+62-21-5551235', $update->json('data.fax'));
        $this->assertSame('https://files.example/logo.png', $update->json('data.logo_url'));
        $this->assertSame(6, $update->json('data.workshop_working_days'));
        $this->assertSame($tenant->id, $update->json('data.id'));
    }

    public function test_workshop_working_days_is_required_and_restricted_to_five_six_or_seven(): void
    {
        $tenant = $this->makeTenant(['code' => 'COE-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['company.update']);
        $headers = $this->authHeaders($token);

        $this->putJson('/api/v1/app/account/company', [], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('workshop_working_days');

        $this->putJson('/api/v1/app/account/company', ['workshop_working_days' => 4], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('workshop_working_days');

        $this->putJson('/api/v1/app/account/company', ['workshop_working_days' => 7], $headers)
            ->assertOk()
            ->assertJsonPath('data.workshop_working_days', 7);
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

    public function test_tenant_can_upload_a_logo_and_the_public_url_replaces_the_previous_one(): void
    {
        $tenant = $this->makeTenant(['code' => 'COL-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['company.update']);
        $headers = $this->authHeaders($token);

        $first = UploadedFile::fake()->image('logo.jpg', 200, 200);
        $upload = $this->postJson('/api/v1/app/account/company/logo', ['file' => $first], $headers)->assertOk();

        $logoUrl = $upload->json('data.logo_url');
        $this->assertNotNull($logoUrl);
        $this->assertStringContainsString('/storage/tenant-logos/', $logoUrl);

        $firstPath = 'tenant-logos/'.$tenant->id.'/'.basename(parse_url($logoUrl, PHP_URL_PATH));
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->image('logo2.png', 200, 200);
        $replace = $this->postJson('/api/v1/app/account/company/logo', ['file' => $second], $headers)->assertOk();

        $secondUrl = $replace->json('data.logo_url');
        $this->assertNotSame($logoUrl, $secondUrl);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_company_logo_upload_rejects_disallowed_mime_type(): void
    {
        $tenant = $this->makeTenant(['code' => 'COM-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, ['company.update']);

        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');
        $this->postJson('/api/v1/app/account/company/logo', ['file' => $file], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_company_logo_upload_denied_without_permission(): void
    {
        $tenant = $this->makeTenant(['code' => 'CON-'.Str::random(4)]);
        [, $token] = $this->makeTenantUser($tenant, []);

        $file = UploadedFile::fake()->image('logo.jpg', 200, 200);
        $this->postJson('/api/v1/app/account/company/logo', ['file' => $file], $this->authHeaders($token))
            ->assertStatus(403);
    }
}
