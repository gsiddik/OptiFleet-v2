<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TireSpeedRating;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Product-level Tire List and Tire Detail inventory: Products of Item Type TIRE with New /
 * Installed / Used counts from their physical tires; the list counts equal the detail tables.
 */
class TireProductInventoryTest extends TestCase
{
    private const PERMISSIONS = ['tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.remove', 'tire.inspect', 'tire.scrap'];

    private function scenario(array $permissions = self::PERMISSIONS): array
    {
        $tenant = $this->makeTenant(['code' => 'TPI-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 123 TPI', 'current_odometer' => 50000]);
        [, $token] = $this->makeTenantUser($tenant, $permissions);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Michelin X Multi', 'brand' => 'Michelin']);

        return [$tenant, $vehicle, $product, $this->authHeaders($token)];
    }

    private function tire($tenant, $product, string $serial, string $status = 'IN_STOCK'): Tire
    {
        return Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => $status]);
    }

    private function install(array $headers, Tire $tire, $vehicle, string $position, $odometer): void
    {
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => $position, 'odometer' => $odometer], $headers)->assertStatus(201);
    }

    private function remove(array $headers, Tire $tire, $odometer, string $disposition = 'REUSE'): void
    {
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'worn', 'disposition' => $disposition, 'odometer' => $odometer], $headers)->assertSuccessful();
    }

    private function rows(array $headers, string $productId, string $category): array
    {
        return $this->getJson("/api/v1/app/tire-products/{$productId}/inventory?category={$category}", $headers)->assertOk()->json('data');
    }

    /** New 2, Installed 1, Used 3 (removed, retread, back in stock after use), 1 scrapped (not stock). */
    private function stock($tenant, $vehicle, $product, array $headers): array
    {
        $this->tire($tenant, $product, 'SN-NEW-1');
        $this->tire($tenant, $product, 'SN-NEW-2', 'RESERVED');
        $installed = $this->tire($tenant, $product, 'SN-INST');
        $this->install($headers, $installed, $vehicle, 'FL', 1000);

        $removed = $this->tire($tenant, $product, 'SN-USED-ROT');
        $this->install($headers, $removed, $vehicle, 'FR', 1000);
        $this->postJson("/api/v1/app/tires/{$removed->id}/rotate", ['to_position' => 'RL', 'odometer' => 3000], $headers)->assertStatus(201);
        $this->remove($headers, $removed, 4500.5);

        $retread = $this->tire($tenant, $product, 'SN-USED-RETREAD');
        $this->install($headers, $retread, $vehicle, 'RR', 2000);
        $this->remove($headers, $retread, 2600, 'RETREAD');

        $reused = $this->tire($tenant, $product, 'SN-USED-INSTOCK');
        $this->install($headers, $reused, $vehicle, 'RR', 2600);
        $this->remove($headers, $reused, 2700);
        $reused->refresh()->update(['current_status' => 'IN_STOCK']);

        $this->tire($tenant, $product, 'SN-SCRAP', 'SCRAPPED');

        return compact('installed', 'removed', 'retread', 'reused');
    }

    public function test_tire_list_is_product_level_with_inventory_counts(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();
        $this->stock($tenant, $vehicle, $product, $headers);
        $empty = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Bridgestone R150', 'brand' => 'Bridgestone']);
        $this->makeProduct($tenant, null, null, ['product_type' => 'SPARE_PART', 'name' => 'Brake Pad']);
        ProductTireSpec::query()->create([
            'product_id' => $product->id, 'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295,
            'aspect_ratio_percent' => 80, 'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
            'single_load_index_id' => TireLoadIndex::query()->create(['code' => '152', 'max_load_single_kg' => 3550, 'status' => 'ACTIVE'])->id,
            'speed_rating_id' => TireSpeedRating::query()->create(['code' => 'M', 'max_speed_kmh' => 130, 'status' => 'ACTIVE'])->id,
            'tire_size_computed' => '295/80 R22.5',
        ]);

        $list = $this->getJson('/api/v1/app/tire-products', $headers)->assertOk();
        $rows = collect($list->json('data'))->keyBy('name');
        $this->assertSame(['Bridgestone R150', 'Michelin X Multi'], $rows->keys()->sort()->values()->all());
        $michelin = $rows['Michelin X Multi'];
        $this->assertSame(['Michelin', '22.5', 2, 1, 3], [$michelin['brand'], (string) $michelin['rim_diameter_inch'], $michelin['new_qty'], $michelin['installed_qty'], $michelin['used_qty']]);
        $this->assertSame([0, 0, 0], [$rows['Bridgestone R150']['new_qty'], $rows['Bridgestone R150']['installed_qty'], $rows['Bridgestone R150']['used_qty']]);
        $this->assertSame(1, collect($list->json('data'))->where('id', $product->id)->count()); // one row per product, not per serial
        $this->assertSame(1, $this->getJson('/api/v1/app/tire-products?search=bridge', $headers)->json('meta.total'));
        $this->assertSame($empty->id, $this->getJson('/api/v1/app/tire-products?search=bridge', $headers)->json('data.0.id'));
    }

    public function test_detail_counts_equal_inventory_tables_and_rows_carry_their_columns(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();
        $tires = $this->stock($tenant, $vehicle, $product, $headers);
        TireInspection::query()->create(['tenant_id' => $tenant->id, 'tire_id' => $tires['removed']->id, 'tread_depth_mm' => 9.0, 'inspected_at' => now()->subDays(5)]);
        TireInspection::query()->create(['tenant_id' => $tenant->id, 'tire_id' => $tires['removed']->id, 'tread_depth_mm' => 6.25, 'inspected_at' => now()->subDay()]);

        $detail = $this->getJson("/api/v1/app/tire-products/{$product->id}", $headers)->assertOk();
        $detail->assertJsonPath('data.name', 'Michelin X Multi')->assertJsonPath('data.product_type', 'TIRE')
            ->assertJsonPath('data.inventory', ['new_qty' => 2, 'installed_qty' => 1, 'used_qty' => 3]);

        $new = $this->rows($headers, $product->id, 'NEW');
        $installed = $this->rows($headers, $product->id, 'INSTALLED');
        $used = collect($this->rows($headers, $product->id, 'USED'))->keyBy('serial_number');
        $this->assertSame([2, 1, 3], [count($new), count($installed), $used->count()]);
        $this->assertSame([['SN-NEW-1', 'IN_STOCK'], ['SN-NEW-2', 'RESERVED']], array_map(fn ($r) => [$r['serial_number'], $r['current_status']], $new));
        $this->assertSame(['SN-INST', 'B 123 TPI', '50000.00'], [$installed[0]['serial_number'], $installed[0]['registration_number'], (string) $installed[0]['current_odometer']]);

        // Usage accumulates over rotation: (3000 − 1000) + (4500.50 − 3000) = 3500.50.
        $this->assertSame('3500.50', $used['SN-USED-ROT']['usage_km']);
        $this->assertSame('600.00', $used['SN-USED-RETREAD']['usage_km']);
        $this->assertSame('100.00', $used['SN-USED-INSTOCK']['usage_km']);
        $this->assertNull($used['SN-USED-ROT']['usage_hours']);
        $this->assertSame('6.25', (string) $used['SN-USED-ROT']['current_tread_depth_mm']);
        $this->assertNull($used['SN-USED-RETREAD']['current_tread_depth_mm']);
        // Domain status is preserved even though the category is "Used".
        $this->assertSame(['REMOVED', 'RETREAD', 'IN_STOCK'], [$used['SN-USED-ROT']['current_status'], $used['SN-USED-RETREAD']['current_status'], $used['SN-USED-INSTOCK']['current_status']]);
    }

    public function test_removing_an_installed_tire_moves_it_from_installed_to_used(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();
        $tire = $this->tire($tenant, $product, 'SN020');
        $this->install($headers, $tire, $vehicle, 'FL', 10000);
        $this->assertSame(['new_qty' => 0, 'installed_qty' => 1, 'used_qty' => 0], $this->getJson("/api/v1/app/tire-products/{$product->id}", $headers)->json('data.inventory'));

        $this->remove($headers, $tire, 12000);
        $this->assertSame(['new_qty' => 0, 'installed_qty' => 0, 'used_qty' => 1], $this->getJson("/api/v1/app/tire-products/{$product->id}", $headers)->json('data.inventory'));
        $this->assertSame([], $this->rows($headers, $product->id, 'INSTALLED'));
        $this->assertSame('2000.00', $this->rows($headers, $product->id, 'USED')[0]['usage_km']);
        $this->assertSame(1, $tire->installations()->count()); // history kept
    }

    public function test_inventory_tables_are_paginated_server_side(): void
    {
        [$tenant, , $product, $headers] = $this->scenario();
        foreach (range(1, 6) as $i) {
            $this->tire($tenant, $product, "SN00{$i}");
        }
        $page = $this->getJson("/api/v1/app/tire-products/{$product->id}/inventory?category=NEW&per_page=5", $headers)->assertOk();
        $page->assertJsonPath('meta.total', 6)->assertJsonPath('meta.last_page', 2)->assertJsonCount(5, 'data');
        $this->getJson("/api/v1/app/tire-products/{$product->id}/inventory?category=OTHER", $headers)->assertStatus(422);
    }

    public function test_access_rules(): void
    {
        [$tenant, , $product, $headers] = $this->scenario();
        $sparePart = $this->makeProduct($tenant, null, null, ['product_type' => 'SPARE_PART']);
        $this->getJson("/api/v1/app/tire-products/{$sparePart->id}", $headers)->assertNotFound();

        [, , , $otherHeaders] = $this->scenario();
        $this->getJson("/api/v1/app/tire-products/{$product->id}", $otherHeaders)->assertNotFound();
        $this->assertNotContains($product->id, array_column($this->getJson('/api/v1/app/tire-products', $otherHeaders)->json('data'), 'id'));

        [, $noTireToken] = $this->makeTenantUser($tenant, ['product.view']);
        $this->getJson('/api/v1/app/tire-products', $this->authHeaders($noTireToken))->assertForbidden();

        // A soft-deleted Tire product stays reachable for its physical tires' history.
        $this->tire($tenant, $product, 'SN-HIST');
        Product::query()->whereKey($product->id)->first()->delete();
        $this->getJson("/api/v1/app/tire-products/{$product->id}", $headers)->assertOk()->assertJsonPath('data.inventory.new_qty', 1);
        $this->assertNotContains($product->id, array_column($this->getJson('/api/v1/app/tire-products', $headers)->json('data'), 'id'));
    }

    public function test_new_tire_context_locks_item_type_and_component_group(): void
    {
        [$tenant, , , $headers] = $this->scenario([...self::PERMISSIONS, 'product.view', 'product.create']);
        $tyre = $this->makeComponentGroup(['code' => 'CG-TYRE', 'abbreviation' => 'WTY', 'name' => 'Wheel & Tyre System']);
        $tyreCategory = ComponentCategory::query()->create(['tenant_id' => null, 'component_group_id' => $tyre->id, 'code' => 'TIRE', 'name' => 'Tire', 'is_system' => true, 'status' => 'ACTIVE']);
        $loadIndex = TireLoadIndex::query()->create(['code' => '91', 'max_load_single_kg' => 615, 'status' => 'ACTIVE']);
        $speed = TireSpeedRating::query()->create(['code' => 'T', 'max_speed_kmh' => 190, 'status' => 'ACTIVE']);
        $payload = fn (array $overrides) => array_merge([
            'name' => 'Dunlop Enasave', 'product_type' => 'TIRE', 'creation_context' => 'TIRE',
            'product_category_id' => $this->makeProductCategory(['item_type' => 'TIRE'])->id, 'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $this->makeWarehouseBin($tenant)->id, 'brand' => 'Dunlop',
            'component_group_id' => $tyre->id, 'component_category_id' => $tyreCategory->id,
            'spec' => ['vehicle_group' => 'CAR', 'pattern_name' => 'Enasave', 'width_mm' => 185, 'aspect_ratio_percent' => 70, 'construction_type' => 'RADIAL',
                'rim_diameter_inch' => 14, 'tire_type' => 'TUBELESS', 'single_load_index_id' => $loadIndex->id, 'speed_rating_id' => $speed->id],
        ], $overrides);

        // The locked fields are enforced by the backend, not only by the disabled inputs.
        $this->postJson('/api/v1/app/products', $payload(['product_type' => 'RIM']), $headers)->assertStatus(422)->assertJsonValidationErrors('product_type');
        $other = $this->componentClassification();
        $this->postJson('/api/v1/app/products', $payload($other), $headers)->assertStatus(422)->assertJsonValidationErrors('component_group_id');

        $created = $this->postJson('/api/v1/app/products', $payload([]), $headers)->assertStatus(201)->json('data');
        $this->assertArrayNotHasKey('creation_context', $created);

        // One Product, visible in both the Products list and the Tire List — no sync step.
        $this->assertContains($created['id'], array_column($this->getJson('/api/v1/app/products?product_type=TIRE', $headers)->json('data'), 'id'));
        $this->assertContains($created['id'], array_column($this->getJson('/api/v1/app/tire-products', $headers)->json('data'), 'id'));

        // The generic Products form (no context) keeps its rules: any Item Type / group.
        $this->postJson('/api/v1/app/products', $payload(['creation_context' => null, 'name' => 'Generic tire'] + $other), $headers)->assertStatus(201);
    }
}
