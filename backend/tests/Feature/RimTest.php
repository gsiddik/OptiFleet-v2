<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Rim;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Final reconciliation — G-09: a Rim entity was previously considered and
 * declined for lack of evidence; the live-VMS analysis document (now
 * available) directly observes Rim as its own real master-data screen with
 * a distinct field set, superseding that earlier decision.
 */
class RimTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'RIM-'.Str::random(4)]);
        $this->grantModule($tenant, 'TIRE');
        [, $token] = $this->makeTenantUser($tenant, ['rim.view', 'rim.manage']);

        return [$tenant, $token];
    }

    public function test_rim_can_be_created_updated_and_deleted(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/rims', [
            'code' => 'RIM-001', 'brand' => 'Enkei', 'material' => 'Alloy',
            'width_inch' => 8.5, 'diameter_inch' => 22.5, 'bolt_holes' => 10, 'pcd_mm' => 335,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->assertSame('Enkei', $create->json('data.brand'));
        $this->assertSame(10, $create->json('data.bolt_holes'));

        $this->putJson("/api/v1/app/rims/{$id}", ['brand' => 'Alcoa'], $headers)
            ->assertOk()->assertJsonPath('data.brand', 'Alcoa');

        $this->deleteJson("/api/v1/app/rims/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('rims', ['id' => $id]);
    }

    public function test_rim_code_must_be_unique_per_tenant(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        Rim::query()->create(['tenant_id' => $tenant->id, 'code' => 'DUP', 'brand' => 'Enkei', 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/rims', ['code' => 'DUP', 'brand' => 'Alcoa'], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_rim_search_filters_by_brand_or_code(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        Rim::query()->create(['tenant_id' => $tenant->id, 'code' => 'R-A', 'brand' => 'Enkei', 'status' => 'ACTIVE']);
        Rim::query()->create(['tenant_id' => $tenant->id, 'code' => 'R-B', 'brand' => 'Alcoa', 'status' => 'ACTIVE']);

        $response = $this->getJson('/api/v1/app/rims?search=Enkei', $this->authHeaders($token))->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Enkei', $response->json('data.0.brand'));
    }

    public function test_rim_is_tenant_isolated(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'RIMB-'.Str::random(4)]);
        $foreignRim = Rim::query()->create(['tenant_id' => $otherTenant->id, 'code' => 'FOREIGN', 'brand' => 'Foreign Brand', 'status' => 'ACTIVE']);

        $this->getJson("/api/v1/app/rims/{$foreignRim->id}", $this->authHeaders($token))->assertStatus(404);
        $this->putJson("/api/v1/app/rims/{$foreignRim->id}", ['brand' => 'Hacked'], $this->authHeaders($token))->assertStatus(404);
    }

    public function test_rim_action_requires_permission(): void
    {
        $tenant = $this->makeTenant(['code' => 'RIMC-'.Str::random(4)]);
        $this->grantModule($tenant, 'TIRE');
        [, $token] = $this->makeTenantUser($tenant, []);

        $this->postJson('/api/v1/app/rims', ['code' => 'X', 'brand' => 'Y'], $this->authHeaders($token))->assertStatus(403);
    }
}
