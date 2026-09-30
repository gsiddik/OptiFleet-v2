<?php

namespace Tests\Feature;

use App\Domain\Inventory\Services\InventoryService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Warehouse Stock tabs are classified by the canonical Item Type, server-side:
 * Parts & Supplies = SPARE_PART, CONSUMABLE, RIM, TIRE; Tools & Equipment = TOOL, EQUIPMENT.
 * Tenants have no Product Categories menu, but still read categories for product forms.
 */
class WarehouseStockTabsTest extends TestCase
{
    public function test_item_group_tabs_are_disjoint_and_follow_item_type(): void
    {
        $tenant = $this->makeTenant(['code' => 'WST-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $inventory = app(InventoryService::class);
        $names = [];
        foreach (['SPARE_PART', 'CONSUMABLE', 'RIM', 'TIRE', 'TOOL', 'EQUIPMENT'] as $type) {
            $product = $this->makeProduct($tenant, null, null, ['name' => "Item {$type}", 'product_type' => $type]);
            $inventory->receive($warehouse, $product, 3, 10, 'OPENING', null, null, null);
            $names[$type] = $product->name;
        }
        [, $token] = $this->makeTenantUser($tenant, ['inventory.view']);
        $headers = $this->authHeaders($token);
        $list = fn (string $query) => collect($this->getJson("/api/v1/app/inventory?{$query}", $headers)->assertOk()->json('data'))->pluck('product.name')->sort()->values()->all();

        $parts = $list('item_group=PARTS_SUPPLIES');
        $tools = $list('item_group=TOOLS_EQUIPMENT');
        $this->assertSame(collect(['SPARE_PART', 'CONSUMABLE', 'RIM', 'TIRE'])->map(fn ($t) => $names[$t])->sort()->values()->all(), $parts);
        $this->assertSame([$names['EQUIPMENT'], $names['TOOL']], $tools);
        $this->assertSame([], array_values(array_intersect($parts, $tools)), 'A product never appears in both tabs.');

        // Pagination and search work inside a tab.
        $response = $this->getJson('/api/v1/app/inventory?item_group=PARTS_SUPPLIES&per_page=2', $headers)->assertOk();
        $this->assertSame(4, $response->json('meta.total'));
        $this->assertCount(2, $response->json('data'));
        $this->assertSame([$names['TIRE']], $list('item_group=PARTS_SUPPLIES&search=TIRE'));
        $this->assertSame([], $list('item_group=TOOLS_EQUIPMENT&search=TIRE'));

        $this->getJson('/api/v1/app/inventory?item_group=OTHER', $headers)->assertStatus(422)->assertJsonValidationErrors('item_group');
    }

    public function test_item_group_filter_stays_tenant_scoped(): void
    {
        $tenant = $this->makeTenant(['code' => 'WSA-'.Str::random(4)]);
        $other = $this->makeTenant(['code' => 'WSB-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $inventory = app(InventoryService::class);
        $inventory->receive($this->makeWarehouse($other, $this->makeBranch($other)), $this->makeProduct($other, null, null, ['product_type' => 'TOOL']), 1, 1, 'OPENING', null, null, null);
        [, $token] = $this->makeTenantUser($tenant, ['inventory.view']);

        $this->getJson('/api/v1/app/inventory?item_group=TOOLS_EQUIPMENT', $this->authHeaders($token))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_tenants_read_but_never_manage_product_categories(): void
    {
        $tenant = $this->makeTenant(['code' => 'WSC-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $category = $this->makeProductCategory(['name' => 'Brake System']);
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update']);
        $headers = $this->authHeaders($token);

        $this->getJson('/api/v1/app/product-categories?per_page=200', $headers)->assertOk()->assertJsonFragment(['name' => 'Brake System']);
        $this->postJson('/api/v1/app/product-categories', ['name' => 'X'], $headers)->assertStatus(405);
        $this->putJson("/api/v1/app/product-categories/{$category->id}", ['name' => 'X'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/app/product-categories/{$category->id}", [], $headers)->assertNotFound();
        $this->getJson('/api/v1/platform/product-categories', $headers)->assertStatus(403);
        $this->assertSame('Brake System', $category->fresh()->name);
    }
}
