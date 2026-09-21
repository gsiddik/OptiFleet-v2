<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — G-12: Uom previously had neither update nor delete, despite
 * being routed through the same 'product.*' permission group as the
 * Product resource itself.
 *
 * Product Category CRUD moved to the platform portal ("Next Improvement
 * Tenant Portal - Products": Product Categories are Superadmin-managed
 * only) — see PlatformProductCategoryTest for that coverage. This file
 * keeps only Uom, which remains tenant-governed.
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

    public function test_tenant_can_read_but_not_write_product_categories(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);
        $category = $this->makeProductCategory();

        $this->getJson('/api/v1/app/product-categories', $headers)->assertOk();
        // The write routes no longer exist on the tenant side at all — the
        // GET route on the same URI still resolves, so an unsupported verb
        // is a 405, while a URI with no route at all (the {id} variants) is a 404.
        $this->postJson('/api/v1/app/product-categories', ['code' => 'X', 'name' => 'X'], $headers)->assertStatus(405);
        $this->putJson("/api/v1/app/product-categories/{$category->id}", ['name' => 'X'], $headers)->assertStatus(404);
        $this->deleteJson("/api/v1/app/product-categories/{$category->id}", [], $headers)->assertStatus(404);
    }

    public function test_uom_can_be_created_updated_and_deleted(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/uoms', ['code' => 'BOX', 'name' => 'Box', 'description' => 'Cardboard box'], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertSame('Cardboard box', $create->json('data.description'));

        $this->putJson("/api/v1/app/uoms/{$id}", ['name' => 'Boxes', 'description' => 'Updated'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Boxes')->assertJsonPath('data.description', 'Updated');

        $this->deleteJson("/api/v1/app/uoms/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('uoms', ['id' => $id]);
    }

    public function test_uom_description_is_optional_and_search_filters_by_code_or_name(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/uoms', ['code' => 'NODESC', 'name' => 'No Description'], $headers)
            ->assertStatus(201)->assertJsonPath('data.description', null);

        $this->makeUom(['tenant_id' => $tenant->id, 'code' => 'KG', 'name' => 'Kilogram', 'is_system' => false]);
        $this->makeUom(['tenant_id' => $tenant->id, 'code' => 'LT', 'name' => 'Liter', 'is_system' => false]);

        $response = $this->getJson('/api/v1/app/uoms?search=Kilogram', $headers)->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('KG'));
        $this->assertFalse($codes->contains('LT'));
    }

    public function test_uom_in_use_by_a_product_cannot_be_deleted(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $uom = $this->makeUom(['tenant_id' => $tenant->id, 'is_system' => false]);
        $this->makeProduct($tenant, null, $uom);

        $this->deleteJson("/api/v1/app/uoms/{$uom->id}", [], $this->authHeaders($token))->assertStatus(422);
        $this->assertDatabaseHas('uoms', ['id' => $uom->id, 'deleted_at' => null]);
    }

    public function test_uom_is_isolated_from_another_tenants_records(): void
    {
        [, $token] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'PCUB-'.Str::random(4)]);
        $foreignUom = $this->makeUom(['tenant_id' => $otherTenant->id, 'is_system' => false]);

        $this->putJson("/api/v1/app/uoms/{$foreignUom->id}", ['name' => 'X'], $this->authHeaders($token))->assertStatus(404);
    }
}
