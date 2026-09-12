<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\VehicleBrand;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — G-13: Vehicle brand/model were always free-text with no shared
 * master data behind them. New VehicleBrand/VehicleModel tables are purely
 * additive — the free-text brand/model columns are untouched.
 */
class VehicleBrandAndModelTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'VBM-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'CORE');
        [, $token] = $this->makeTenantUser($tenant, [
            'vehicle_brand.view', 'vehicle_brand.create', 'vehicle_brand.update', 'vehicle.view', 'vehicle.create',
        ]);

        return [$tenant, $token];
    }

    public function test_brand_and_model_can_be_created_and_a_model_requires_its_brand(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $brand = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'HINO', 'name' => 'Hino'], $headers)->assertStatus(201);
        $brandId = $brand->json('data.id');

        $model = $this->postJson('/api/v1/app/vehicle-models', [
            'vehicle_brand_id' => $brandId, 'code' => 'DUTRO', 'name' => 'Dutro',
        ], $headers)->assertStatus(201);

        $this->assertSame($brandId, $model->json('data.vehicle_brand_id'));
        $this->assertSame('Hino', $model->json('data.brand.name'));
    }

    public function test_model_code_must_be_unique_within_its_brand_but_not_globally(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $brandA = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'HINO', 'name' => 'Hino'], $headers)->json('data.id');
        $brandB = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'ISUZU', 'name' => 'Isuzu'], $headers)->json('data.id');

        $this->postJson('/api/v1/app/vehicle-models', ['vehicle_brand_id' => $brandA, 'code' => '300', 'name' => 'Series 300'], $headers)->assertStatus(201);
        $this->postJson('/api/v1/app/vehicle-models', ['vehicle_brand_id' => $brandA, 'code' => '300', 'name' => 'Duplicate'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/app/vehicle-models', ['vehicle_brand_id' => $brandB, 'code' => '300', 'name' => 'Same code, different brand'], $headers)->assertStatus(201);
    }

    public function test_vehicle_can_optionally_link_a_brand_and_model_alongside_the_existing_free_text_fields(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $headers = $this->authHeaders($token);

        $brandId = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'HINO', 'name' => 'Hino'], $headers)->json('data.id');
        $modelId = $this->postJson('/api/v1/app/vehicle-models', ['vehicle_brand_id' => $brandId, 'code' => 'DUTRO', 'name' => 'Dutro'], $headers)->json('data.id');

        $response = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id,
            'brand' => 'Hino', 'model' => 'Dutro', 'vehicle_brand_id' => $brandId, 'vehicle_model_id' => $modelId,
            'registration_number' => 'B 1234 XYZ',
        ], $headers)->assertStatus(201);

        $this->assertSame('Hino', $response->json('data.brand'));
        $this->assertSame($brandId, $response->json('data.vehicle_brand_id'));
        $this->assertSame($modelId, $response->json('data.vehicle_model_id'));
    }

    public function test_vehicle_creation_without_brand_and_model_master_data_still_works(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();

        $response = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id,
            'brand' => 'Generic Brand', 'model' => 'Generic Model', 'registration_number' => 'B 5678 XYZ',
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertNull($response->json('data.vehicle_brand_id'));
        $this->assertSame('Generic Brand', $response->json('data.brand'));
    }

    public function test_brand_in_use_by_a_vehicle_cannot_be_deleted(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $headers = $this->authHeaders($token);

        $brandId = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'HINO', 'name' => 'Hino'], $headers)->json('data.id');
        $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id,
            'brand' => 'Hino', 'model' => 'Dutro', 'vehicle_brand_id' => $brandId, 'registration_number' => 'B 9999 XYZ',
        ], $headers)->assertStatus(201);

        $this->deleteJson("/api/v1/app/vehicle-brands/{$brandId}", [], $headers)->assertStatus(422);
    }

    public function test_brand_and_model_are_isolated_from_another_tenants_records(): void
    {
        [, $token] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'VBMB-'.Str::random(4)]);
        $foreignBrand = VehicleBrand::query()->create([
            'tenant_id' => $otherTenant->id, 'code' => 'FOREIGN', 'name' => 'Foreign Brand', 'is_system' => false, 'status' => 'ACTIVE',
        ]);

        $this->putJson("/api/v1/app/vehicle-brands/{$foreignBrand->id}", ['name' => 'Hacked'], $this->authHeaders($token))->assertStatus(404);
    }
}
