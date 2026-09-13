<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Models\ProductCompatibility;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductTest extends TestCase
{
    public function test_product_crud_works(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRD-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/products', [
            'code' => 'BRK-PAD-01', 'sku' => 'SKU-BRK-01', 'name' => 'Brake Pad Set',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id,
        ], $headers)->assertStatus(201);
        $productId = $create->json('data.id');

        $this->getJson("/api/v1/app/products/{$productId}", $headers)->assertOk()->assertJsonPath('data.name', 'Brake Pad Set');

        $this->putJson("/api/v1/app/products/{$productId}", ['name' => 'Brake Pad Set (Front)'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Brake Pad Set (Front)');

        $list = $this->getJson('/api/v1/app/products', $headers)->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains('id', $productId));
    }

    public function test_product_spec_fields_are_optional_and_stored(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRDS-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/products', [
            'code' => 'BRK-PAD-02', 'sku' => 'SKU-BRK-02', 'name' => 'Brake Pad Set',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id,
            'manufacturer' => 'Bosch', 'material' => 'Ceramic', 'production_year' => 2024,
        ], $headers)->assertStatus(201);

        $this->assertSame('Bosch', $create->json('data.manufacturer'));
        $this->assertSame('Ceramic', $create->json('data.material'));
        $this->assertSame(2024, $create->json('data.production_year'));

        $id = $create->json('data.id');
        $this->putJson("/api/v1/app/products/{$id}", [
            'weight_kg' => 1.5, 'length_mm' => 200, 'width_mm' => 100, 'height_mm' => 50,
            'image_url' => 'https://files.example/products/brake-pad.jpg',
        ], $headers)->assertOk()->assertJsonPath('data.image_url', 'https://files.example/products/brake-pad.jpg');
    }

    public function test_product_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant(['code' => 'PRDA-'.Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'PRDB-'.Str::random(4)]);
        $this->grantModule($tenantA, 'INVENTORY');
        $this->grantModule($tenantB, 'INVENTORY');
        $product = $this->makeProduct($tenantA);

        [, $tokenB] = $this->makeTenantUser($tenantB, ['product.view']);

        $this->getJson("/api/v1/app/products/{$product->id}", $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_product_compatibility_resolution_prefers_more_specific_rule(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRDC-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['brand' => 'Hino', 'model' => 'Ranger']);
        $componentGroup = $this->makeComponentGroup();

        $genericProduct = $this->makeProduct($tenant, null, null, ['name' => 'Generic Brake Pad']);
        $specificProduct = $this->makeProduct($tenant, null, null, ['name' => 'Hino Ranger Brake Pad']);

        ProductCompatibility::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $genericProduct->id, 'component_group_id' => $componentGroup->id,
            'vehicle_category_id' => $category->id,
        ]);
        ProductCompatibility::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $specificProduct->id, 'component_group_id' => $componentGroup->id,
            'vehicle_category_id' => $category->id, 'vehicle_brand' => 'Hino', 'vehicle_model' => 'Ranger',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'vehicle.view']);

        $result = $this->getJson("/api/v1/app/products/compatible?vehicle_id={$vehicle->id}&component_group_id={$componentGroup->id}", $this->authHeaders($token))->assertOk();
        $ids = collect($result->json('data'))->pluck('id')->all();

        $this->assertSame($specificProduct->id, $ids[0]);
        $this->assertContains($genericProduct->id, $ids);
    }
}
