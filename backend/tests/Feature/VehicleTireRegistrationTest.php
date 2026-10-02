<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Initial / last-known tire registration from Vehicle Detail → Wheels Configuration: physical tire
 * create/reuse, baseline installation, Tire List visibility — and no warehouse stock effect.
 */
class VehicleTireRegistrationTest extends TestCase
{
    private const PERMISSIONS = ['tire.view', 'tire.manage', 'tire.install', 'wheel_configuration.map_vehicle'];

    private function scenario(array $permissions = self::PERMISSIONS): array
    {
        $tenant = $this->makeTenant(['code' => 'VTR-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        [$user, $token] = $this->makeTenantUser($tenant, $permissions);
        $headers = $this->authHeaders($token);
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['vehicle_type' => 'Car', 'axle_count' => 2, 'wheel_count' => 6, 'registration_number' => 'B 7788 TR']);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Michelin X Multi']);

        return [$tenant, $branch, $category, $vehicle, $product, $headers, $user];
    }

    /** Maps the vehicle to Passenger Car 1.2 (1FL1 1FR1 1RL1 1RL2 1RR1 1RR2). */
    private function mapVehicle(array $headers, Vehicle $vehicle): string
    {
        $id = $this->postJson('/api/v1/app/wheel-configuration-masters', ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => [2], 'spare_tires' => 0], $headers)->json('data.master.id');
        $this->putJson("/api/v1/app/wheel-configuration-masters/{$id}/vehicle-mappings", ['add_vehicle_ids' => [$vehicle->id], 'remove_vehicle_ids' => []], $headers)->assertOk();

        return $id;
    }

    private function register(array $headers, Vehicle $vehicle, array $overrides = [])
    {
        return $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration/tires", array_merge([
            'position_code' => '1FL1',
            'installed_date' => '2026-09-01',
            'installed_time' => '07:30',
            'installation_km' => '12500.75',
            'product_id' => null,
            'serial_number' => 'SN-12345',
            'tread_depth_mm' => '8.5',
        ], $overrides), $headers);
    }

    private function stockSnapshot(string $tenantId): array
    {
        return [
            'on_hand' => (string) DB::table('warehouse_stocks')->where('tenant_id', $tenantId)->sum('quantity_on_hand'),
            'reserved' => (string) DB::table('warehouse_stocks')->where('tenant_id', $tenantId)->sum('quantity_reserved'),
            'movements' => DB::table('stock_movements')->where('tenant_id', $tenantId)->count(),
            'reservations' => DB::table('stock_reservations')->where('tenant_id', $tenantId)->count(),
            'transfers' => DB::table('stock_transfers')->where('tenant_id', $tenantId)->count(),
        ];
    }

    public function test_initial_registration_installs_the_tire_without_touching_warehouse_stock(): void
    {
        [$tenant, $branch, , $vehicle, $product, $headers, $user] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        DB::table('warehouse_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->stockSnapshot($tenant->id);

        $this->register($headers, $vehicle, ['product_id' => $product->id, 'serial_number' => '  SN-12345 '])->assertStatus(201)
            ->assertJsonPath('data.installations.0.position_code', '1FL1');

        $tire = Tire::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(['SN-12345', $product->id, 'INSTALLED', $vehicle->id, '1FL1', null], [$tire->serial_number, $tire->product_id, $tire->current_status, $tire->current_vehicle_id, $tire->current_position, $tire->current_warehouse_id]);
        $installation = TireInstallation::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->sole();
        $this->assertSame('INITIAL_REGISTRATION', $installation->installation_source);
        $this->assertSame('12500.75', (string) $installation->installation_odometer);
        $this->assertSame('2026-09-01 00:30:00', $installation->installed_at->utc()->format('Y-m-d H:i:s')); // 07:30 Asia/Jakarta
        $this->assertSame($user->id, $installation->performed_by);
        $this->assertSame('8.50', (string) TireInspection::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->sole()->tread_depth_mm);

        // Warehouse Stock changed: NO — Inventory movement/reservation/transfer created: NO.
        $this->assertSame($before, $this->stockSnapshot($tenant->id));
        $this->assertEquals(10, (float) DB::table('warehouse_stocks')->where('product_id', $product->id)->value('quantity_on_hand'));

        // Vehicle tab shows it back in the tenant timezone, decimals intact.
        $tab = $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration", $headers)->assertOk();
        $tab->assertJsonPath('data.installations.0.installed_date', '2026-09-01')
            ->assertJsonPath('data.installations.0.installed_time', '07:30')
            ->assertJsonPath('data.installations.0.installation_odometer', '12500.75')
            ->assertJsonPath('data.installations.0.tread_depth_mm', '8.50')
            ->assertJsonPath('data.installations.0.installation_source', 'INITIAL_REGISTRATION')
            ->assertJsonPath('data.installations.0.tire.product.name', 'Michelin X Multi')
            ->assertJsonPath('data.installations.0.tire.current_status', 'INSTALLED');

        // Tire List: serial, product, vehicle, position, status INSTALLED.
        $list = $this->getJson('/api/v1/app/tires?search=SN-12345', $headers)->assertOk();
        $list->assertJsonPath('data.0.serial_number', 'SN-12345')
            ->assertJsonPath('data.0.product.id', $product->id)
            ->assertJsonPath('data.0.current_vehicle.registration_number', 'B 7788 TR')
            ->assertJsonPath('data.0.current_position', '1FL1')
            ->assertJsonPath('data.0.current_status', 'INSTALLED');
    }

    public function test_an_installed_serial_and_an_occupied_position_are_rejected(): void
    {
        [, , , $vehicle, $product, $headers] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $this->register($headers, $vehicle, ['product_id' => $product->id])->assertStatus(201);

        $this->register($headers, $vehicle, ['product_id' => $product->id, 'position_code' => '1FR1', 'serial_number' => 'sn-12345'])
            ->assertStatus(422)->assertJsonValidationErrors('serial_number');
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'serial_number' => 'SN-OTHER'])
            ->assertStatus(422)->assertJsonValidationErrors('position_code');
        $this->assertSame(1, Tire::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, TireInstallation::query()->withoutGlobalScopes()->whereNull('removed_at')->count());
    }

    public function test_existing_tire_records_are_reused_only_when_safe(): void
    {
        [$tenant, $branch, , $vehicle, $product, $headers] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $loose = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-LOOSE', 'current_status' => 'IN_STOCK']);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-IN-WH', 'current_status' => 'IN_STOCK', 'current_warehouse_id' => $warehouse->id]);
        $otherProduct = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $otherProduct->id, 'serial_number' => 'SN-OTHER-PRODUCT', 'current_status' => 'IN_STOCK']);
        Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SCRAPPED', 'current_status' => 'SCRAPPED']);

        $this->register($headers, $vehicle, ['product_id' => $product->id, 'serial_number' => 'sn-loose'])->assertStatus(201);
        $this->assertSame(['INSTALLED', '1FL1'], [$loose->fresh()->current_status, $loose->fresh()->current_position]);
        $this->assertSame(4, Tire::query()->withoutGlobalScopes()->count()); // reused, no duplicate

        $this->register($headers, $vehicle, ['product_id' => $product->id, 'position_code' => '1FR1', 'serial_number' => 'SN-IN-WH'])->assertStatus(422)->assertJsonValidationErrors('serial_number');
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'position_code' => '1FR1', 'serial_number' => 'SN-OTHER-PRODUCT'])->assertStatus(422)->assertJsonValidationErrors('serial_number');
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'position_code' => '1FR1', 'serial_number' => 'SN-SCRAPPED'])->assertStatus(422)->assertJsonValidationErrors('serial_number');
    }

    public function test_position_product_and_input_validation(): void
    {
        [$tenant, $branch, $category, $vehicle, $product, $headers] = $this->scenario();
        $unmapped = $this->makeVehicle($tenant, $branch, $category);
        $this->register($headers, $unmapped, ['product_id' => $product->id])->assertStatus(422)->assertJsonValidationErrors('position_code');

        $this->mapVehicle($headers, $vehicle);
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'position_code' => '2RL1'])->assertStatus(422)->assertJsonValidationErrors('position_code');
        $sparePart = $this->makeProduct($tenant, null, null, ['product_type' => 'SPARE_PART']);
        $this->register($headers, $vehicle, ['product_id' => $sparePart->id])->assertStatus(422)->assertJsonValidationErrors('product_id');

        foreach ([['installed_time' => '25:00'], ['installed_time' => '7:30'], ['installed_time' => '07:30:00'], ['installed_date' => '01/09/2026'], ['installation_km' => '-1'], ['installation_km' => '12500.755'], ['installation_km' => 'abc'], ['tread_depth_mm' => '8.555'], ['serial_number' => '   ']] as $bad) {
            $this->register($headers, $vehicle, ['product_id' => $product->id] + $bad)->assertStatus(422)->assertJsonValidationErrors(array_keys($bad)[0]);
        }
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'installed_date' => now()->addDays(2)->format('Y-m-d')])->assertStatus(422)->assertJsonValidationErrors('installed_date');
        $this->assertSame(0, Tire::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // KM is required (an estimate is acceptable); tread depth is optional.
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'installation_km' => null])->assertStatus(422)->assertJsonValidationErrors('installation_km');
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'installation_km' => '   '])->assertStatus(422)->assertJsonValidationErrors('installation_km');
        $this->assertSame(0, Tire::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'installation_km' => '0', 'tread_depth_mm' => null])->assertStatus(201);
        $this->assertSame(0, TireInspection::query()->withoutGlobalScopes()->count());
    }

    public function test_the_generic_install_uses_the_mapped_configuration_positions(): void
    {
        [$tenant, , , $vehicle, $product, $headers] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-GENERIC', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)
            ->assertStatus(422)->assertJsonPath('message', "'FRONT_LEFT' is not a position of this vehicle's wheel configuration.");
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => '1RL2'], $headers)->assertStatus(201);
        $this->assertSame('STANDARD', TireInstallation::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->sole()->installation_source);
    }

    public function test_tire_product_lookup_permissions_and_tenant_isolation(): void
    {
        [$tenant, , , $vehicle, $product, $headers] = $this->scenario();
        $this->makeProduct($tenant, null, null, ['product_type' => 'SPARE_PART', 'name' => 'Michelin Brake Pad']);
        $this->mapVehicle($headers, $vehicle);

        $found = $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration/tire-products?search=michelin", $headers)->assertOk()->json('data');
        $this->assertSame([$product->id], array_column($found, 'id'));

        [, $viewToken] = $this->makeTenantUser($tenant, ['tire.view']);
        $this->register($this->authHeaders($viewToken), $vehicle, ['product_id' => $product->id])->assertForbidden();

        [, , , , , $otherHeaders] = $this->scenario();
        $this->register($otherHeaders, $vehicle, ['product_id' => $product->id])->assertNotFound();
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration/tire-products", $otherHeaders)->assertNotFound();
        $this->assertSame(0, Tire::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    /** Vehicle registration feeds the product-level Tire List / Tire Detail: Installed, never a new product row or stock. */
    public function test_registered_tires_count_as_installed_on_their_tire_product(): void
    {
        [$tenant, $branch, , $vehicle, $product, $headers] = $this->scenario([...self::PERMISSIONS, 'tire.remove']);
        $vehicle->update(['current_odometer' => 20000]);
        $this->mapVehicle($headers, $vehicle);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        DB::table('warehouse_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-LOOSE', 'current_status' => 'IN_STOCK']);
        $inventory = fn () => $this->getJson("/api/v1/app/tire-products/{$product->id}", $headers)->json('data.inventory');
        $this->assertSame(['new_qty' => 1, 'installed_qty' => 0, 'used_qty' => 0], $inventory());
        $before = $this->stockSnapshot($tenant->id);

        // A new serial → a tire of this product, Installed.
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'serial_number' => 'SN-NEW-REG'])->assertStatus(201);
        // An existing loose serial is reused: it moves New → Installed, no duplicate.
        $this->register($headers, $vehicle, ['product_id' => $product->id, 'serial_number' => 'sn-loose', 'position_code' => '1FR1'])->assertStatus(201);
        $this->assertSame(['new_qty' => 0, 'installed_qty' => 2, 'used_qty' => 0], $inventory());

        $installed = $this->getJson("/api/v1/app/tire-products/{$product->id}/inventory?category=INSTALLED", $headers)->json('data');
        $this->assertSame([['SN-LOOSE', 'B 7788 TR', '20000.00'], ['SN-NEW-REG', 'B 7788 TR', '20000.00']], array_map(fn ($r) => [$r['serial_number'], $r['registration_number'], (string) $r['current_odometer']], $installed));
        $this->assertSame(2, Tire::query()->where('product_id', $product->id)->count());
        $list = collect($this->getJson('/api/v1/app/tire-products', $headers)->json('data'))->where('id', $product->id);
        $this->assertCount(1, $list); // one product row, not a row per registration
        $this->assertSame([0, 2, 0], [$list->first()['new_qty'], $list->first()['installed_qty'], $list->first()['used_qty']]);
        $this->assertSame($before, $this->stockSnapshot($tenant->id)); // warehouse stock untouched

        // Removal: Installed → Used; usage KM counts from the registered installation KM.
        $tire = Tire::query()->where('serial_number', 'SN-NEW-REG')->first();
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'worn', 'disposition' => 'REUSE', 'odometer' => 15000], $headers)->assertSuccessful();
        $this->assertSame(['new_qty' => 0, 'installed_qty' => 1, 'used_qty' => 1], $inventory());
        $this->assertSame('2499.25', $this->getJson("/api/v1/app/tire-products/{$product->id}/inventory?category=USED", $headers)->json('data.0.usage_km'));
    }
}
