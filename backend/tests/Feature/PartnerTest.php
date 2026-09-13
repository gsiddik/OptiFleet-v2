<?php

namespace Tests\Feature;

use App\Domain\Partner\Services\PartnerPerformanceService;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerTest extends TestCase
{
    public function test_partner_crud_works(): void
    {
        $tenant = $this->makeTenant(['code' => 'PTR-'.Str::random(4)]);
        $this->grantModule($tenant, 'PARTNER');
        [, $token] = $this->makeTenantUser($tenant, ['partner.view', 'partner.manage']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/partners', [
            'code' => 'VND-01', 'name' => 'Acme Spare Parts', 'partner_type' => 'SPARE_PART_SUPPLIER',
        ], $headers)->assertStatus(201);
        $partnerId = $create->json('data.id');

        $this->getJson("/api/v1/app/partners/{$partnerId}", $headers)->assertOk()
            ->assertJsonPath('data.name', 'Acme Spare Parts');

        $this->putJson("/api/v1/app/partners/{$partnerId}", ['name' => 'Acme Spare Parts Ltd'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Acme Spare Parts Ltd');

        $list = $this->getJson('/api/v1/app/partners', $headers)->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains('id', $partnerId));
    }

    public function test_partner_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant(['code' => 'PTRA-'.Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'PTRB-'.Str::random(4)]);
        $this->grantModule($tenantA, 'PARTNER');
        $this->grantModule($tenantB, 'PARTNER');
        $partner = $this->makePartner($tenantA);

        [, $tokenB] = $this->makeTenantUser($tenantB, ['partner.view']);

        $this->getJson("/api/v1/app/partners/{$partner->id}", $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_partner_performance_summary_aggregates_events(): void
    {
        $tenant = $this->makeTenant(['code' => 'PTRC-'.Str::random(4)]);
        $this->grantModule($tenant, 'PARTNER');
        $partner = $this->makePartner($tenant);
        $performance = app(PartnerPerformanceService::class);

        $performance->record($partner, 'PO_ISSUED', null, null, null, 1000.0);
        $performance->record($partner, 'DELIVERY_ON_TIME');
        $performance->record($partner, 'DELIVERY_LATE');
        $performance->record($partner, 'GOODS_ACCEPTED', null, null, 8.0);
        $performance->record($partner, 'GOODS_REJECTED', null, null, 2.0);

        [, $token] = $this->makeTenantUser($tenant, ['partner.view']);
        $response = $this->getJson("/api/v1/app/partners/{$partner->id}", $this->authHeaders($token))->assertOk();

        $this->assertSame(1, $response->json('data.performance.purchase_orders_issued'));
        $this->assertSame(1, $response->json('data.performance.deliveries_on_time'));
        $this->assertSame(1, $response->json('data.performance.deliveries_late'));
        $this->assertSame(50.0, (float) $response->json('data.performance.on_time_rate'));
        $this->assertSame(8.0, (float) $response->json('data.performance.quantity_accepted'));
        $this->assertSame(2.0, (float) $response->json('data.performance.quantity_rejected'));
        $this->assertSame(1000.0, (float) $response->json('data.performance.total_purchase_value'));
    }

    public function test_partner_profile_fields_are_stored_and_returned(): void
    {
        $tenant = $this->makeTenant(['code' => 'PTRE-'.Str::random(4)]);
        $this->grantModule($tenant, 'PARTNER');
        [, $token] = $this->makeTenantUser($tenant, ['partner.view', 'partner.manage']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/partners', [
            'code' => 'VND-02', 'name' => 'Bank-Linked Supplier', 'partner_type' => 'SUPPLIER',
            'province' => 'DKI Jakarta', 'city' => 'Jakarta Selatan',
            'bank' => 'BCA', 'account_holder' => 'PT Bank-Linked Supplier', 'account_number' => '1234567890',
            'description' => 'Preferred oil supplier',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->assertSame('DKI Jakarta', $create->json('data.province'));
        $this->assertSame('BCA', $create->json('data.bank'));
        $this->assertSame('1234567890', $create->json('data.account_number'));

        $this->putJson("/api/v1/app/partners/{$id}", ['city' => 'Jakarta Pusat', 'description' => 'Updated'], $headers)
            ->assertOk()->assertJsonPath('data.city', 'Jakarta Pusat')->assertJsonPath('data.description', 'Updated');
    }

    public function test_duplicate_partner_code_within_tenant_is_rejected(): void
    {
        $tenant = $this->makeTenant(['code' => 'PTRD-'.Str::random(4)]);
        $this->grantModule($tenant, 'PARTNER');
        $this->makePartner($tenant, ['code' => 'VND-DUP']);
        [, $token] = $this->makeTenantUser($tenant, ['partner.manage']);

        $this->postJson('/api/v1/app/partners', [
            'code' => 'VND-DUP', 'name' => 'Another Vendor', 'partner_type' => 'SUPPLIER',
        ], $this->authHeaders($token))->assertStatus(422);
    }
}
