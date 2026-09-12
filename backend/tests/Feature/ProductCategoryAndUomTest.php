<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — G-12: ProductCategory previously had no delete endpoint and
 * Uom had neither update nor delete, despite both being routed through the
 * same 'product.*' permission group as the Product resource itself.
 */
class ProductCategoryAndUomTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'PCU-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update', 'product.delete']);

        return [$tenant, $token];
    }

    public function test_product_category_can_be_created_updated_and_deleted(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/product-categories', ['code' => 'CAT-1', 'name' => 'Brakes'], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->putJson("/api/v1/app/product-categories/{$id}", ['name' => 'Brake Parts'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Brake Parts');

        $this->deleteJson("/api/v1/app/product-categories/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('product_categories', ['id' => $id]);
    }

    public function test_product_category_in_use_by_a_product_cannot_be_deleted(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $category = $this->makeProductCategory(['tenant_id' => $tenant->id, 'is_system' => false]);
        $this->makeProduct($tenant, $category);

        $this->deleteJson("/api/v1/app/product-categories/{$category->id}", [], $this->authHeaders($token))->assertStatus(422);
        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_system_product_category_cannot_be_modified_by_a_tenant(): void
    {
        [, $token] = $this->setUpTenant();
        $systemCategory = $this->makeProductCategory(['is_system' => true, 'tenant_id' => null]);

        $this->putJson("/api/v1/app/product-categories/{$systemCategory->id}", ['name' => 'Hacked'], $this->authHeaders($token))->assertStatus(403);
        $this->deleteJson("/api/v1/app/product-categories/{$systemCategory->id}", [], $this->authHeaders($token))->assertStatus(403);
    }

    public function test_uom_can_be_created_updated_and_deleted(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/uoms', ['code' => 'BOX', 'name' => 'Box'], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->putJson("/api/v1/app/uoms/{$id}", ['name' => 'Boxes'], $headers)->assertOk()->assertJsonPath('data.name', 'Boxes');

        $this->deleteJson("/api/v1/app/uoms/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('uoms', ['id' => $id]);
    }

    public function test_uom_in_use_by_a_product_cannot_be_deleted(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $uom = $this->makeUom(['tenant_id' => $tenant->id, 'is_system' => false]);
        $this->makeProduct($tenant, null, $uom);

        $this->deleteJson("/api/v1/app/uoms/{$uom->id}", [], $this->authHeaders($token))->assertStatus(422);
        $this->assertDatabaseHas('uoms', ['id' => $uom->id, 'deleted_at' => null]);
    }

    public function test_product_category_and_uom_are_isolated_from_another_tenants_records(): void
    {
        [, $token] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'PCUB-'.Str::random(4)]);
        $foreignCategory = $this->makeProductCategory(['tenant_id' => $otherTenant->id, 'is_system' => false]);
        $foreignUom = $this->makeUom(['tenant_id' => $otherTenant->id, 'is_system' => false]);

        $this->putJson("/api/v1/app/product-categories/{$foreignCategory->id}", ['name' => 'X'], $this->authHeaders($token))->assertStatus(404);
        $this->putJson("/api/v1/app/uoms/{$foreignUom->id}", ['name' => 'X'], $this->authHeaders($token))->assertStatus(404);
    }
}
