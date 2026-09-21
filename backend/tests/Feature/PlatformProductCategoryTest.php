<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Models\ProductCategory;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Next Improvement Tenant Portal - Products": "Fitur Product Categories
 * hanya dikelola oleh Superadmin" — Product Category management is
 * platform-only. See ProductCategoryAndUomTest for the tenant-side
 * read-only confirmation.
 */
class PlatformProductCategoryTest extends TestCase
{
    public function test_platform_user_with_permission_can_create_update_and_delete_a_category(): void
    {
        [, $token] = $this->makePlatformUser(['product_category.view', 'product_category.create', 'product_category.update', 'product_category.delete']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/platform/product-categories', ['code' => 'CAT-'.Str::random(4), 'name' => 'Brakes'], $headers)
            ->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertDatabaseHas('product_categories', ['id' => $id, 'tenant_id' => null, 'is_system' => true]);

        $this->putJson("/api/v1/platform/product-categories/{$id}", ['name' => 'Brake Parts'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Brake Parts');

        $this->getJson('/api/v1/platform/product-categories', $headers)->assertOk();

        $this->deleteJson("/api/v1/platform/product-categories/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('product_categories', ['id' => $id]);
    }

    public function test_platform_user_without_permission_is_denied(): void
    {
        [, $token] = $this->makePlatformUser([]);

        $this->postJson('/api/v1/platform/product-categories', ['code' => 'X', 'name' => 'X'], $this->authHeaders($token))
            ->assertStatus(403);
    }

    public function test_subcategory_inherits_parent_item_type_regardless_of_client_input(): void
    {
        [, $token] = $this->makePlatformUser(['product_category.view', 'product_category.create', 'product_category.update']);
        $headers = $this->authHeaders($token);

        $parent = $this->postJson('/api/v1/platform/product-categories', [
            'code' => 'CAT-SP-'.Str::random(4), 'name' => 'Sparepart Category', 'item_type' => 'SPARE_PART',
        ], $headers)->assertStatus(201);

        $child = $this->postJson('/api/v1/platform/product-categories', [
            'code' => 'CAT-SP-SUB-'.Str::random(4), 'name' => 'Brake', 'parent_id' => $parent->json('data.id'), 'item_type' => 'TIRE',
        ], $headers)->assertStatus(201);

        $this->assertSame('SPARE_PART', $child->json('data.item_type'));

        // Attempting to change a subcategory's item_type independently is a no-op.
        $this->putJson("/api/v1/platform/product-categories/{$child->json('data.id')}", ['item_type' => 'TOOL'], $headers)
            ->assertOk()->assertJsonPath('data.item_type', 'SPARE_PART');
    }

    public function test_category_with_subcategories_cannot_be_deleted(): void
    {
        [, $token] = $this->makePlatformUser(['product_category.create', 'product_category.delete']);
        $headers = $this->authHeaders($token);
        $parent = ProductCategory::query()->create(['tenant_id' => null, 'code' => 'PAR-'.Str::random(4), 'name' => 'Parent', 'is_system' => true, 'status' => 'ACTIVE']);
        ProductCategory::query()->create(['tenant_id' => null, 'code' => 'CHI-'.Str::random(4), 'name' => 'Child', 'parent_id' => $parent->id, 'is_system' => true, 'status' => 'ACTIVE']);

        $this->deleteJson("/api/v1/platform/product-categories/{$parent->id}", [], $headers)->assertStatus(422);
    }

    public function test_category_in_use_by_a_product_cannot_be_deleted(): void
    {
        [, $token] = $this->makePlatformUser(['product_category.delete']);
        $tenant = $this->makeTenant(['code' => 'PPC-'.Str::random(4)]);
        $category = $this->makeProductCategory(['tenant_id' => null, 'is_system' => true]);
        $this->makeProduct($tenant, $category);

        $this->deleteJson("/api/v1/platform/product-categories/{$category->id}", [], $this->authHeaders($token))->assertStatus(422);
    }
}
