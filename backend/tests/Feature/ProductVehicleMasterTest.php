<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Product Vehicle Compatibility Brand/Model reference the Vehicle Brand / Vehicle Model
 * masters (dependent dropdowns), and Vehicle Brand "Brand Of" references the Vehicle
 * Category master.
 */
class ProductVehicleMasterTest extends TestCase
{
    private function tenantWithUser(array $permissions = ['product.view', 'product.create', 'product.update']): array
    {
        $tenant = $this->makeTenant(['code' => 'PVM-'.Str::upper(Str::random(4))]);
        foreach (['CORE', 'INVENTORY', 'ORGANIZATION', 'VEHICLE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        [, $token] = $this->makeTenantUser($tenant, $permissions);

        return [$tenant, $this->authHeaders($token)];
    }

    private function brand(?string $tenantId, string $name, string $status = 'ACTIVE'): VehicleBrand
    {
        return VehicleBrand::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'code' => 'VB-'.Str::upper(Str::random(6)), 'name' => $name, 'is_system' => $tenantId === null, 'status' => $status,
        ]);
    }

    private function model(VehicleBrand $brand, string $name, string $status = 'ACTIVE'): VehicleModel
    {
        return VehicleModel::query()->withoutGlobalScopes()->create([
            'tenant_id' => $brand->tenant_id, 'vehicle_brand_id' => $brand->id, 'code' => 'VM-'.Str::upper(Str::random(6)), 'name' => $name, 'is_system' => false, 'status' => $status,
        ]);
    }

    private function sparepart($tenant, array $compatibility): array
    {
        $group = ComponentGroup::query()->whereNull('tenant_id')->where('abbreviation', 'BRK')->first()
            ?? $this->makeComponentGroup(['abbreviation' => 'BRK', 'name' => 'Brake']);
        $category = ComponentCategory::query()->firstOrCreate(
            ['tenant_id' => null, 'component_group_id' => $group->id, 'code' => 'GENERAL'],
            ['name' => 'General', 'is_system' => true, 'status' => 'ACTIVE']
        );

        return [
            'name' => 'Brake Pad '.Str::random(4), 'product_category_id' => $this->makeProductCategory()->id, 'product_type' => 'SPARE_PART',
            'uom_id' => $this->makeUom()->id, 'default_storage_bin_id' => $this->makeWarehouseBin($tenant)->id, 'brand' => 'Akebono', 'track_serial_number' => false,
            'component_group_id' => $group->id, 'component_category_id' => $category->id,
            'spec' => ['part_number' => 'PN-'.Str::random(5), 'part_type' => 'GENUINE', 'compatibilities' => [$compatibility]],
        ];
    }

    public function test_compatibility_stores_brand_and_model_references_and_restores_them(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $toyota = $this->brand($tenant->id, 'Toyota');
        $fortuner = $this->model($toyota, 'Fortuner');

        $id = $this->postJson('/api/v1/app/products', $this->sparepart($tenant, ['vehicle_brand_id' => $toyota->id, 'vehicle_model_id' => $fortuner->id]), $headers)
            ->assertStatus(201)->json('data.id');

        $rule = ProductCompatibility::query()->where('product_id', $id)->firstOrFail();
        $this->assertSame([$toyota->id, 'Toyota', $fortuner->id, 'Fortuner'], [$rule->vehicle_brand_id, $rule->vehicle_brand, $rule->vehicle_model_id, $rule->vehicle_model]);

        // Edit/Detail read-back restores the selection by id (plus the master names).
        $show = $this->getJson("/api/v1/app/products/{$id}", $headers)->assertOk();
        $this->assertSame($toyota->id, $show->json('data.compatibilities.0.vehicle_brand_id'));
        $this->assertSame($fortuner->id, $show->json('data.compatibilities.0.vehicle_model_id'));
        $this->assertSame('Fortuner', $show->json('data.compatibilities.0.vehicle_model'), 'The name snapshot stays a string in the contract.');
        $this->assertSame('Fortuner', $show->json('data.compatibilities.0.model_master.name'));
        $this->assertSame('Toyota', $show->json('data.compatibilities.0.brand_master.name'));
    }

    public function test_invalid_brand_model_selections_are_rejected(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $toyota = $this->brand($tenant->id, 'Toyota');
        $hino = $this->brand($tenant->id, 'Hino');
        $fortuner = $this->model($toyota, 'Fortuner');
        $ranger = $this->model($hino, 'Ranger');
        $retired = $this->brand($tenant->id, 'Retired', 'INACTIVE');
        [$otherTenant] = $this->tenantWithUser();
        $foreign = $this->brand($otherTenant->id, 'Foreign');
        $foreignModel = $this->model($foreign, 'X');

        $cases = [
            'model from another brand' => [['vehicle_brand_id' => $toyota->id, 'vehicle_model_id' => $ranger->id], 'spec.compatibilities.0.vehicle_model_id'],
            'inactive brand' => [['vehicle_brand_id' => $retired->id, 'vehicle_model_id' => $this->model($retired, 'Old')->id], 'spec.compatibilities.0.vehicle_brand_id'],
            "another tenant's brand" => [['vehicle_brand_id' => $foreign->id, 'vehicle_model_id' => $foreignModel->id], 'spec.compatibilities.0.vehicle_brand_id'],
            'free text only' => [['vehicle_brand' => 'Toyota', 'vehicle_model' => 'Fortuner'], 'spec.compatibilities.0.vehicle_brand_id'],
            'inactive model' => [['vehicle_brand_id' => $toyota->id, 'vehicle_model_id' => $this->model($toyota, 'Old', 'INACTIVE')->id], 'spec.compatibilities.0.vehicle_model_id'],
        ];
        foreach ($cases as $label => [$row, $key]) {
            $response = $this->postJson('/api/v1/app/products', $this->sparepart($tenant, $row), $headers)->assertStatus(422);
            $errorKeys = array_keys($response->json('errors') ?? []);
            $this->assertTrue(
                in_array($key, $errorKeys, true) || in_array(Str::after($key, 'spec.'), $errorKeys, true),
                "{$label}: expected an error on {$key}, got ".implode(', ', $errorKeys)
            );
        }
        $this->assertSame(0, ProductCompatibility::query()->where('tenant_id', $tenant->id)->count());
        $this->assertNotNull($fortuner);
    }

    public function test_detail_add_rule_accepts_brand_model_ids_or_any(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $product = $this->makeProduct($tenant);
        $hino = $this->brand(null, 'Hino');
        $ranger = $this->model($hino, 'Ranger FG');

        $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", ['vehicle_brand_id' => $hino->id, 'vehicle_model_id' => $ranger->id], $headers)
            ->assertStatus(201)->assertJsonPath('data.vehicle_brand', 'Hino')->assertJsonPath('data.vehicle_model_id', $ranger->id);
        $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", ['vehicle_brand_id' => $hino->id], $headers)
            ->assertStatus(201)->assertJsonPath('data.vehicle_model_id', null);
        $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", [], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", ['vehicle_model_id' => $ranger->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_brand_id');
    }

    public function test_lookups_list_active_visible_brands_and_only_the_brands_models(): void
    {
        [$tenant, $headers] = $this->tenantWithUser(['product.view']);
        $toyota = $this->brand($tenant->id, 'Toyota');
        $hino = $this->brand(null, 'Hino');
        $this->brand($tenant->id, 'Retired', 'INACTIVE');
        [$otherTenant] = $this->tenantWithUser();
        $this->brand($otherTenant->id, 'Foreign');
        $fortuner = $this->model($toyota, 'Fortuner');
        $this->model($toyota, 'Old', 'INACTIVE');
        $this->model($hino, 'Ranger');

        $names = collect($this->getJson('/api/v1/app/product-classification/vehicle-brands', $headers)->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Toyota') && $names->contains('Hino'));
        $this->assertFalse($names->contains('Retired') || $names->contains('Foreign'));

        $models = $this->getJson('/api/v1/app/product-classification/vehicle-models?vehicle_brand_id='.$toyota->id, $headers)->assertOk()->json('data');
        $this->assertSame([$fortuner->id], array_column($models, 'id'));
        $this->getJson('/api/v1/app/product-classification/vehicle-models', $headers)->assertStatus(422);

        [, $noView] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $this->getJson('/api/v1/app/product-classification/vehicle-brands', $this->authHeaders($noView))->assertForbidden();
    }

    public function test_compatibility_backfill_links_only_unambiguous_names(): void
    {
        $tenant = $this->makeTenant();
        $product = $this->makeProduct($tenant);
        $own = $this->brand($tenant->id, 'Toyota');
        $ownModel = $this->model($own, 'Fortuner');
        $this->brand(null, 'Toyota'); // tenant brand wins over a same-named platform brand
        $platform = $this->brand(null, 'Hino');
        $this->brand(null, 'Isuzu');
        $this->brand(null, 'ISUZU '); // ambiguous

        $row = fn (string $brand, ?string $model) => ProductCompatibility::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'vehicle_brand' => $brand, 'vehicle_model' => $model]);
        $a = $row(' toyota', 'FORTUNER');
        $b = $row('Hino', 'Unknown Model');
        $c = $row('Isuzu', null);
        $d = $row('Nobody', 'X');

        $migration = require database_path('migrations/2026_10_01_000001_link_product_compatibilities_to_vehicle_masters.php');
        $migration->backfill();
        $migration->backfill();

        $this->assertSame([$own->id, $ownModel->id], [$a->fresh()->vehicle_brand_id, $a->fresh()->vehicle_model_id]);
        $this->assertSame([$platform->id, null], [$b->fresh()->vehicle_brand_id, $b->fresh()->vehicle_model_id]);
        $this->assertNull($c->fresh()->vehicle_brand_id, 'Ambiguous names are never guessed.');
        $this->assertNull($d->fresh()->vehicle_brand_id);
        $this->assertSame(' toyota', $a->fresh()->vehicle_brand, 'Legacy text is kept.');
        $this->assertSame(['Nobody', 'X'], [$d->fresh()->vehicle_brand, $d->fresh()->vehicle_model], 'Unmatched legacy text is retained as-is.');
        $this->assertSame(['Isuzu', null], [$c->fresh()->vehicle_brand, $c->fresh()->vehicle_model]);
    }

    public function test_brand_of_uses_active_vehicle_categories_and_persists(): void
    {
        [$tenant, $headers] = $this->tenantWithUser(['vehicle_brand.view', 'vehicle_brand.create', 'vehicle_brand.update']);
        $car = $this->makeVehicleCategory(['name' => 'Passenger Car']);
        $truck = $this->makeVehicleCategory(['name' => 'Truck']);
        $inactive = $this->makeVehicleCategory(['name' => 'Retired', 'status' => 'INACTIVE']);

        $id = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'TOY', 'name' => 'Toyota', 'vehicle_category_ids' => [$car->id, $truck->id]], $headers)
            ->assertStatus(201)->json('data.id');
        $listed = collect($this->getJson('/api/v1/app/vehicle-brands', $headers)->json('data'))->firstWhere('id', $id);
        $this->assertEqualsCanonicalizing(['Passenger Car', 'Truck'], array_column($listed['vehicle_categories'], 'name'));

        $this->putJson("/api/v1/app/vehicle-brands/{$id}", ['vehicle_category_ids' => [$inactive->id]], $headers)->assertStatus(422)->assertJsonValidationErrors('vehicle_category_ids');
        $this->putJson("/api/v1/app/vehicle-brands/{$id}", ['vehicle_category_ids' => [$truck->id]], $headers)->assertOk();
        $this->assertSame([$truck->id], VehicleBrand::query()->withoutGlobalScopes()->findOrFail($id)->vehicleCategories()->pluck('vehicle_categories.id')->all());

        // A category deactivated after it was linked stays accepted (no silent loss on save).
        VehicleCategory::query()->whereKey($truck->id)->update(['status' => 'INACTIVE']);
        $this->putJson("/api/v1/app/vehicle-brands/{$id}", ['name' => 'Toyota Motor', 'vehicle_category_ids' => [$truck->id]], $headers)->assertOk();
        // Omitting vehicle_category_ids leaves the links unchanged.
        $this->putJson("/api/v1/app/vehicle-brands/{$id}", ['name' => 'Toyota'], $headers)->assertOk()->assertJsonCount(1, 'data.vehicle_categories');
    }

    public function test_brand_of_backfill_maps_legacy_usage_types(): void
    {
        foreach (['VC-PCAR' => 'Passenger Car', 'VC-TRUCK' => 'Truck', 'VC-BUS' => 'Bus', 'VC-HEQ' => 'Heavy Equipment'] as $code => $name) {
            VehicleCategory::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => null, 'code' => $code], ['name' => $name, 'status' => 'ACTIVE']);
        }
        $multi = $this->brand(null, 'Legacy Multi');
        DB::table('vehicle_brands')->where('id', $multi->id)->update(['usage_types' => json_encode(['CAR', 'BUS'])]);
        $single = $this->brand(null, 'Legacy Single');
        DB::table('vehicle_brands')->where('id', $single->id)->update(['usage_type' => 'HEAVY_EQUIPMENT']);

        $migration = require database_path('migrations/2026_10_01_000002_create_vehicle_brand_categories_table.php');
        $migration->backfill();
        $migration->backfill();

        $codes = fn (VehicleBrand $b) => VehicleCategory::query()->withoutGlobalScopes()->whereIn('id', DB::table('vehicle_brand_categories')->where('vehicle_brand_id', $b->id)->pluck('vehicle_category_id'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['VC-BUS', 'VC-PCAR'], $codes($multi));
        $this->assertSame(['VC-HEQ'], $codes($single));
    }

    public function test_a_rule_can_be_edited_and_keeps_a_since_retired_selection(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $product = $this->makeProduct($tenant);
        $toyota = $this->brand($tenant->id, 'Toyota');
        $fortuner = $this->model($toyota, 'Fortuner');
        $hino = $this->brand($tenant->id, 'Hino');
        $ranger = $this->model($hino, 'Ranger');

        $ruleId = $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", ['vehicle_brand_id' => $toyota->id, 'vehicle_model_id' => $fortuner->id], $headers)->json('data.id');

        // Change brand -> the old model no longer fits and is rejected; the new brand's model is accepted.
        $this->putJson("/api/v1/app/products/{$product->id}/compatibilities/{$ruleId}", ['vehicle_brand_id' => $hino->id, 'vehicle_model_id' => $fortuner->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_model_id');
        $this->putJson("/api/v1/app/products/{$product->id}/compatibilities/{$ruleId}", ['vehicle_brand_id' => $hino->id, 'vehicle_model_id' => $ranger->id], $headers)
            ->assertOk()->assertJsonPath('data.vehicle_brand', 'Hino')->assertJsonPath('data.model_master.name', 'Ranger');

        // Retire the stored masters: re-saving the unchanged selection still works (no silent loss).
        VehicleBrand::query()->withoutGlobalScopes()->whereKey($hino->id)->update(['status' => 'INACTIVE']);
        VehicleModel::query()->withoutGlobalScopes()->whereKey($ranger->id)->update(['status' => 'INACTIVE']);
        $this->putJson("/api/v1/app/products/{$product->id}/compatibilities/{$ruleId}", ['vehicle_brand_id' => $hino->id, 'vehicle_model_id' => $ranger->id], $headers)->assertOk();
    }

    public function test_compatibility_writes_are_limited_to_the_tenants_own_products(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $system = $this->makeProduct($tenant, null, null, ['tenant_id' => null, 'is_system' => true]);
        $rule = ProductCompatibility::query()->create(['tenant_id' => null, 'product_id' => $system->id]);

        $this->postJson("/api/v1/app/products/{$system->id}/compatibilities", [], $headers)->assertForbidden();
        $this->putJson("/api/v1/app/products/{$system->id}/compatibilities/{$rule->id}", [], $headers)->assertForbidden();
        $this->deleteJson("/api/v1/app/products/{$system->id}/compatibilities/{$rule->id}", [], $headers)->assertForbidden();
    }

    public function test_brand_of_options_are_the_active_vehicle_categories(): void
    {
        [, $headers] = $this->tenantWithUser(['vehicle_brand.view']);
        $active = $this->makeVehicleCategory(['name' => 'Motorcycle']);
        $inactive = $this->makeVehicleCategory(['name' => 'Retired', 'status' => 'INACTIVE']);

        $ids = array_column($this->getJson('/api/v1/app/vehicle-brands/category-options', $headers)->assertOk()->json('data'), 'id');
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }
}
