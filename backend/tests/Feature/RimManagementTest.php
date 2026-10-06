<?php

namespace Tests\Feature;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\Tire\Models\Tire;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tire Management → Rim: Rim Products (Item Type RIM), serial-numbered rims (Component Assets) as New
 * Stock / Installed / Used, Register Rim (New Stock and directly Installed on a wheel position), the
 * locked New Rim context, tire + rim on one position, and the Rim Serial Number Excel import.
 */
class RimManagementTest extends TestCase
{
    private $warehouse;

    private const PERMISSIONS = ['rim.view', 'rim.manage', 'tire.view', 'tire.manage', 'tire.install', 'wheel_configuration.map_vehicle'];

    private function scenario(array $permissions = self::PERMISSIONS, ?array $scopes = null): array
    {
        $tenant = $this->makeTenant(['code' => 'RIM-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE', 'COMPONENT'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['vehicle_type' => 'Car', 'axle_count' => 2, 'wheel_count' => 6, 'registration_number' => 'B 9123 TXA']);
        [$user, $token] = $this->makeTenantUser($tenant, $permissions, $scopes === null ? null : array_map(fn ($v) => $v === 'BRANCH' ? $branch->id : $v, $scopes));
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'RIM', 'name' => 'Steel Rim 22.5x8.25', 'brand' => 'Accuride', 'track_serial_number' => true]);
        DB::table('product_rims')->insert(['product_id' => $product->id, 'rim_type' => 'STEEL', 'diameter_inch' => 22.5, 'width_inch' => 8.25, 'bolt_holes' => 10, 'pcd_mm' => 335, 'material' => 'Steel', 'created_at' => now(), 'updated_at' => now()]);
        $this->warehouse = $this->makeWarehouse($tenant, $branch, null, ['code' => 'WH-RIM']);

        return [$tenant, $branch, $vehicle, $product, $this->authHeaders($token), $user];
    }

    /** Maps the vehicle to Passenger Car 1.2 (1FL1 1FR1 1RL1 1RL2 1RR1 1RR2). */
    private function mapVehicle(array $headers, Vehicle $vehicle): void
    {
        $id = $this->postJson('/api/v1/app/wheel-configuration-masters', ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => [2], 'spare_tires' => 0], $headers)->json('data.master.id');
        $this->putJson("/api/v1/app/wheel-configuration-masters/{$id}/vehicle-mappings", ['add_vehicle_ids' => [$vehicle->id], 'remove_vehicle_ids' => []], $headers)->assertOk();
    }

    /** New Stock rows get the scenario warehouse unless a vehicle (Installed) or a warehouse is given. */
    private function register(array $headers, $product, array $body)
    {
        if (! array_key_exists('vehicle_id', $body) && ! array_key_exists('warehouse_id', $body)) {
            $body['warehouse_id'] = $this->warehouse->id;
        }

        return $this->postJson("/api/v1/app/rim-products/{$product->id}/rims", $body, $headers);
    }

    private function upload(array $rows, string $sheet = 'Fill Here'): UploadedFile
    {
        $content = (new XlsxWriter)->addSheet('How To', [['x']])->addSheet($sheet, $rows)->toString();

        return UploadedFile::fake()->createWithContent('rims.xlsx', $content);
    }

    public function test_rim_list_and_detail_come_from_rim_products_with_spec_and_counts(): void
    {
        [$tenant, , $vehicle, $product, $headers] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $tire = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Not a rim']);

        $this->register($headers, $product, ['serial_number' => 'R-NEW-1'])->assertStatus(201);
        $this->register($headers, $product, ['serial_number' => 'R-INS-1', 'vehicle_id' => $vehicle->id, 'position_code' => '1FL1'])->assertStatus(201);
        $this->register($headers, $product, ['serial_number' => 'R-INS-2', 'vehicle_id' => $vehicle->id, 'position_code' => '1fr1'])->assertStatus(201);

        $list = $this->getJson('/api/v1/app/rim-products', $headers)->assertOk()->json('data');
        $this->assertSame([$product->id], array_column($list, 'id'), 'only Item Type RIM');
        $this->assertSame(['22.50', '8.25', 10, 'STEEL', 1, 2, 0], [$list[0]['diameter_inch'], $list[0]['width_inch'], $list[0]['bolt_holes'], $list[0]['rim_type'], $list[0]['new_qty'], $list[0]['installed_qty'], $list[0]['used_qty']]);

        $detail = $this->getJson("/api/v1/app/rim-products/{$product->id}", $headers)->assertOk();
        $detail->assertJsonPath('data.inventory', ['new_qty' => 1, 'installed_qty' => 2, 'used_qty' => 0]);
        $installed = $this->getJson("/api/v1/app/rim-products/{$product->id}/inventory?category=INSTALLED", $headers)->assertOk()->json('data');
        $this->assertSame(['1FL1', '1FR1'], array_column($installed, 'position_code'), 'the position code is stored as configured');
        $this->assertSame('B 9123 TXA', $installed[0]['registration_number']);
        $this->getJson("/api/v1/app/rim-products/{$tire->id}", $headers)->assertNotFound();

        // A removed rim moves to Used Stock (the component lifecycle is reused).
        $asset = ComponentAsset::query()->where('serial_number', 'R-INS-1')->sole();
        [, $componentToken] = $this->makeTenantUser($tenant, ['component_asset.remove']);
        $this->postJson("/api/v1/app/component-assets/{$asset->id}/remove", ['removal_reason' => 'Cracked', 'disposition' => 'REUSE'], $this->authHeaders($componentToken))->assertStatus(201);
        $this->getJson("/api/v1/app/rim-products/{$product->id}", $headers)->assertJsonPath('data.inventory', ['new_qty' => 1, 'installed_qty' => 1, 'used_qty' => 1]);
    }

    public function test_register_new_stock_and_installed_with_serial_rules(): void
    {
        [$tenant, , $vehicle, $product, $headers, $user] = $this->scenario();

        $created = $this->register($headers, $product, ['serial_number' => '  rim-0001 '])->assertStatus(201)->json('data');
        $this->assertSame(['rim-0001', 'IN_STOCK', null, $product->id, $this->warehouse->id], [$created['serial_number'], $created['current_status'], $created['current_vehicle_id'], $created['product_id'], $created['current_warehouse_id']]);
        // New Stock needs a warehouse of the tenant.
        $this->register($headers, $product, ['serial_number' => 'RIM-W', 'warehouse_id' => null])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->register($headers, $product, ['serial_number' => 'RIM-W', 'warehouse_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->assertMatchesRegularExpression('/^AST-\d{4}-\d{6}$/', $created['asset_number']);
        // Serial uniqueness per tenant ignores case and surrounding spaces.
        $this->register($headers, $product, ['serial_number' => 'RIM-0001'])->assertStatus(422)->assertJsonValidationErrors('serial_number');
        // Another tenant may use the same serial.
        $mine = $this->warehouse;
        [, , , $otherProduct, $otherHeaders] = $this->scenario();
        $this->register($otherHeaders, $otherProduct, ['serial_number' => 'RIM-0001'])->assertStatus(201);
        $this->register($headers, $product, ['serial_number' => 'RIM-X', 'warehouse_id' => $this->warehouse->id])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->warehouse = $mine;

        // Installed: the vehicle needs a Wheels Configuration …
        $this->register($headers, $product, ['serial_number' => 'RIM-0002', 'vehicle_id' => $vehicle->id, 'position_code' => '1FL1'])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
        $this->mapVehicle($headers, $vehicle);
        // … the position must be one of its positions (never free text) …
        $this->register($headers, $product, ['serial_number' => 'RIM-0002', 'vehicle_id' => $vehicle->id, 'position_code' => 'ZZ9'])->assertStatus(422)->assertJsonValidationErrors('position_code');
        $this->register($headers, $product, ['serial_number' => 'RIM-0002', 'vehicle_id' => $vehicle->id])->assertStatus(422)->assertJsonValidationErrors('position_code');
        $installed = $this->register($headers, $product, ['serial_number' => 'RIM-0002', 'vehicle_id' => $vehicle->id, 'position_code' => '1FL1'])->assertStatus(201)->json('data');
        $this->assertSame(['INSTALLED', $vehicle->id], [$installed['current_status'], $installed['current_vehicle_id']]);
        $installation = ComponentInstallation::query()->where('component_asset_id', $installed['id'])->sole();
        $this->assertSame(['1FL1', $vehicle->id, null, $user->id], [$installation->position_location, $installation->vehicle_id, $installation->removed_at, $installation->performed_by]);

        // … and an occupied rim position is never overwritten silently.
        $this->register($headers, $product, ['serial_number' => 'RIM-0003', 'vehicle_id' => $vehicle->id, 'position_code' => '1FL1'])
            ->assertStatus(422)->assertJsonValidationErrors('position_code');
        $this->assertFalse(ComponentAsset::query()->where('serial_number', 'RIM-0003')->exists(), 'nothing is created when the fitment is rejected');
        // No warehouse stock / movement is created by a registration.
        $this->assertSame(0, DB::table('stock_movements')->where('tenant_id', $tenant->id)->count());

        // A deleted product takes no new rims; a tire product is not a rim product.
        $tireProduct = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $this->register($headers, $tireProduct, ['serial_number' => 'X'])->assertNotFound();
        $product->delete();
        $this->register($headers, $product, ['serial_number' => 'RIM-0009'])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_tire_and_rim_coexist_on_the_same_position(): void
    {
        [$tenant, , $vehicle, $product, $headers] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $tireProduct = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Michelin']);
        $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration/tires", [
            'position_code' => '1FL1', 'installed_date' => '2026-09-01', 'installed_time' => '07:30', 'installation_km' => '100',
            'product_id' => $tireProduct->id, 'serial_number' => 'TIRE-1',
        ], $headers)->assertStatus(201);

        $this->register($headers, $product, ['serial_number' => 'RIM-A', 'vehicle_id' => $vehicle->id, 'position_code' => '1FL1'])->assertStatus(201);

        $tire = Tire::query()->where('serial_number', 'TIRE-1')->sole();
        $this->assertSame(['INSTALLED', '1FL1', $vehicle->id], [$tire->current_status, $tire->current_position, $tire->current_vehicle_id], 'the tire is untouched');
        $positions = $this->getJson("/api/v1/app/rim-products/vehicles/{$vehicle->id}/positions", $headers)->assertOk()->json('data');
        $this->assertTrue($positions['has_configuration']);
        $this->assertSame('RIM-A', collect($positions['positions'])->firstWhere('position_code', '1FL1')['rim_serial_number']);
        $this->assertNull(collect($positions['positions'])->firstWhere('position_code', '1FR1')['rim_serial_number']);
        // A tire can still be fitted where a rim is.
        $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration/tires", [
            'position_code' => '1FR1', 'installed_date' => '2026-09-01', 'installed_time' => '07:30', 'installation_km' => '100',
            'product_id' => $tireProduct->id, 'serial_number' => 'TIRE-2',
        ], $headers)->assertStatus(201);
    }

    public function test_installed_registration_respects_tenant_and_branch_scope(): void
    {
        [$tenant, , $vehicle, $product, $headers] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $otherBranch = $this->makeBranch($tenant);
        [, $scopedToken] = $this->makeTenantUser($tenant, self::PERMISSIONS, ['BRANCH' => $otherBranch->id]);
        $scoped = $this->authHeaders($scopedToken);

        $this->register($scoped, $product, ['serial_number' => 'RIM-S', 'vehicle_id' => $vehicle->id, 'position_code' => '1FL1'])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
        $this->getJson("/api/v1/app/rim-products/vehicles/{$vehicle->id}/positions", $scoped)->assertForbidden();

        [, , $foreignVehicle, , $foreignHeaders] = $this->scenario();
        $this->register($headers, $product, ['serial_number' => 'RIM-F', 'vehicle_id' => $foreignVehicle->id, 'position_code' => '1FL1'])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
        $this->getJson("/api/v1/app/rim-products/vehicles/{$foreignVehicle->id}/positions", $headers)->assertNotFound();

        [, $viewerToken] = $this->makeTenantUser($tenant, ['rim.view']);
        $this->register($this->authHeaders($viewerToken), $product, ['serial_number' => 'RIM-V'])->assertForbidden();
    }

    public function test_new_rim_context_locks_item_type_component_group_and_serial_tracking(): void
    {
        [$tenant, , , , $headers] = $this->scenario([...self::PERMISSIONS, 'product.view', 'product.create']);
        $wty = $this->makeComponentGroup(['code' => 'CG-TYRE', 'abbreviation' => 'WTY', 'name' => 'Wheel & Tyre System']);
        $rimCategory = ComponentCategory::query()->create(['tenant_id' => null, 'component_group_id' => $wty->id, 'code' => 'STEEL_RIM', 'name' => 'Steel Rim', 'is_system' => true, 'status' => 'ACTIVE']);
        $other = $this->makeComponentGroup(['code' => 'CG-OTHER-'.Str::random(3), 'name' => 'Other']);
        $payload = fn (array $overrides) => array_merge([
            'name' => 'Alloy Rim 17', 'product_type' => 'RIM', 'creation_context' => 'RIM', 'track_serial_number' => true,
            'product_category_id' => $this->makeProductCategory(['item_type' => 'RIM'])->id, 'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $this->makeWarehouseBin($tenant)->id, 'brand' => 'Enkei',
            'component_group_id' => $wty->id, 'component_category_id' => $rimCategory->id,
            'spec' => ['rim_type' => 'ALLOY', 'diameter_inch' => 17, 'width_inch' => 7.5, 'bolt_holes' => 5, 'pcd_mm' => 114.3],
        ], $overrides);

        $this->postJson('/api/v1/app/products', $payload(['product_type' => 'TIRE']), $headers)->assertStatus(422)->assertJsonValidationErrors('product_type');
        $this->postJson('/api/v1/app/products', $payload(['component_group_id' => $other->id, 'component_category_id' => null]), $headers)->assertStatus(422)->assertJsonValidationErrors('component_group_id');
        $this->postJson('/api/v1/app/products', $payload(['track_serial_number' => false]), $headers)->assertStatus(422)->assertJsonValidationErrors('track_serial_number');

        $created = $this->postJson('/api/v1/app/products', $payload([]), $headers)->assertStatus(201)->json('data');
        $this->assertSame(['RIM', true], [$created['product_type'], (bool) $created['track_serial_number']]);
        $this->assertContains($created['id'], array_column($this->getJson('/api/v1/app/rim-products', $headers)->json('data'), 'id'));
    }

    public function test_serial_templates_have_how_to_and_fill_here_per_mode(): void
    {
        [, , , $product, $headers, $user] = $this->scenario();
        foreach (['NEW_STOCK' => ['Serial Number', 'Warehouse Code'], 'INSTALLED' => ['Serial Number', 'Vehicle Registration', 'Position Code']] as $mode => $headersExpected) {
            $response = $this->get("/api/v1/app/rim-products/{$product->id}/import-template?mode={$mode}", $headers)->assertOk();
            $this->assertStringStartsWith(XlsxWriter::MIME, $response->headers->get('Content-Type'));
            $path = tempnam(sys_get_temp_dir(), 'rim');
            file_put_contents($path, $response->getContent());
            $reader = XlsxReader::open($path);
            $this->assertSame(['How To', 'Fill Here'], $reader->sheetNames());
            $this->assertSame($headersExpected, array_column($reader->rows('Fill Here')[1], 'value'));
            $this->assertCount(1, $reader->rows('Fill Here'), 'Fill Here holds only the header row');
            $howTo = implode("\n", array_map(fn ($r) => implode(' | ', array_column($r, 'value')), $reader->rows('How To')));
            $this->assertStringContainsString('Do not rename the sheets', $howTo);
            $this->assertStringContainsString('only once', $howTo);
            unlink($path);
        }
        // Indonesian user: same (exact) sheet names, Indonesian headers and instructions.
        $user->forceFill(['preferred_locale' => 'id'])->save();
        $this->app['auth']->forgetGuards();
        $id = $this->get("/api/v1/app/rim-products/{$product->id}/import-template?mode=INSTALLED", $headers)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'rim');
        file_put_contents($path, $id->getContent());
        $reader = XlsxReader::open($path);
        $this->assertSame(['How To', 'Fill Here'], $reader->sheetNames());
        $this->assertSame(['Nomor Seri', 'Registrasi Kendaraan', 'Kode Posisi'], array_column($reader->rows('Fill Here')[1], 'value'));
        $this->assertStringContainsString('Jangan mengganti nama sheet', implode(' ', array_map(fn ($r) => implode(' ', array_column($r, 'value')), $reader->rows('How To'))));
        unlink($path);
        // Row errors follow the user's language too.
        $rows = $this->post("/api/v1/app/rim-products/{$product->id}/import/preview?mode=NEW_STOCK", ['file' => $this->upload([['Nomor Seri', 'Kode Gudang'], ['', 'WH-RIM'], ['A-1', 'NOPE']])], $headers)->assertOk()->json('data.rows');
        $this->assertSame('Nomor Seri wajib diisi.', $rows[0]['errors'][0]);
        $this->assertSame('Gudang NOPE tidak ditemukan.', $rows[1]['errors'][0]);
        $this->assertSame('INVALID', $rows[0]['status'], 'statuses stay canonical codes');
        $this->get("/api/v1/app/rim-products/{$product->id}/import-template?mode=BOGUS", $headers)->assertStatus(422);
    }

    public function test_installed_import_classifies_rows_and_imports_valid_ones_only(): void
    {
        [$tenant, , $vehicle, $product, $headers] = $this->scenario();
        $this->mapVehicle($headers, $vehicle);
        $this->register($headers, $product, ['serial_number' => 'DB-1', 'vehicle_id' => $vehicle->id, 'position_code' => '1RL1'])->assertStatus(201);
        $unmapped = $this->makeVehicle($tenant, $this->makeBranch($tenant), $this->makeVehicleCategory(), ['registration_number' => 'B 1 NOCFG']);

        $file = $this->upload([
            ['Serial Number', 'Vehicle Registration', 'Position Code'],
            ['R-1', 'b9123txa', '1fl1'],          // 2 VALID (plate and code are normalized)
            ['R-2', 'B 9123 TXA', '1FL1'],         // 3 DUPLICATE: same vehicle + position as row 2
            ['r-1', 'B 9123 TXA', '1FR1'],         // 4 DUPLICATE: serial repeated in file
            ['db-1', 'B 9123 TXA', '1FR1'],        // 5 DUPLICATE: serial already registered
            ['R-5', 'B 0000 XX', '1FR1'],          // 6 INVALID: unknown vehicle
            ['R-6', 'B 1 NOCFG', '1FR1'],          // 7 INVALID: no Wheels Configuration
            ['R-7', 'B 9123 TXA', 'ZZ9'],          // 8 INVALID: not a position
            ['R-8', 'B 9123 TXA', '1RL1'],         // 9 INVALID: position already has a rim
            ['', 'B 9123 TXA', '1RR1'],            // 10 INVALID: empty serial
            ['=HYPERLINK("x")', 'B 9123 TXA', '1RR2'], // 11 INVALID: formula injection
            ['R-11', 'B 9123 TXA', '1RR2'],        // 12 VALID
        ]);
        $preview = $this->post("/api/v1/app/rim-products/{$product->id}/import/preview?mode=INSTALLED", ['file' => $file], $headers)->assertOk()->json('data');
        $this->assertSame(['total' => 11, 'valid' => 2, 'duplicate' => 3, 'invalid' => 6], $preview['summary']);
        $this->assertSame(['VALID', 'DUPLICATE', 'DUPLICATE', 'DUPLICATE', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'VALID'], array_column($preview['rows'], 'status'));
        $this->assertSame(['serial_number', 'vehicle_registration', 'position_code'], array_column($preview['columns'], 'id'));
        $this->assertStringContainsString('already has rim DB-1', implode(' ', $preview['rows'][7]['errors']));

        // The client sends all rows back; invalid / duplicate ones are rejected again, valid ones are kept.
        $result = $this->postJson("/api/v1/app/rim-products/{$product->id}/import?mode=INSTALLED", [
            'rows' => array_map(fn ($r) => ['row' => $r['row'], 'values' => $r['values']], $preview['rows']),
        ], $headers)->assertOk()->json('data');
        $this->assertSame(['PARTIAL', 2, 9], [$result['status'], $result['imported'], count($result['failed'])]);
        $installs = ComponentInstallation::query()->whereNull('removed_at')->where('vehicle_id', $vehicle->id)->pluck('position_location')->sort()->values()->all();
        $this->assertSame(['1FL1', '1RL1', '1RR2'], $installs);
        $this->assertSame('INSTALLED', ComponentAsset::query()->where('serial_number', 'R-1')->sole()->current_status);
    }

    public function test_new_stock_import_and_workbook_structure_errors(): void
    {
        [, , , $product, $headers] = $this->scenario();
        $preview = fn (UploadedFile $f) => $this->post("/api/v1/app/rim-products/{$product->id}/import/preview?mode=NEW_STOCK", ['file' => $f], $headers);

        $preview($this->upload([['Serial Number', 'Warehouse Code'], ['X', 'WH-RIM']], 'Sheet1'))->assertStatus(422)->assertJsonValidationErrors('file');
        $preview($this->upload([['Serial Number'], ['X']]))->assertStatus(422)->assertJsonValidationErrors('file');
        $preview($this->upload([['Serial Number', 'Warehouse Code', 'Extra'], ['X', 'WH-RIM', 'Y']]))->assertStatus(422)->assertJsonValidationErrors('file');
        $preview($this->upload([['Serial Number', 'Warehouse Code']]))->assertStatus(422)->assertJsonValidationErrors('file');
        $preview(UploadedFile::fake()->createWithContent('rims.xlsx', 'not a zip'))->assertStatus(422)->assertJsonValidationErrors('file');
        $preview(UploadedFile::fake()->createWithContent('rims.csv', "Serial Number\nX"))->assertStatus(422)->assertJsonValidationErrors('file');
        // Indonesian headers are accepted too; a value beyond the template columns invalidates the row.
        $rows = $preview($this->upload([['Nomor Seri', 'Kode Gudang'], ['N-1', 'wh-rim'], ['N-2', 'WH-RIM'], ['n-1', 'WH-RIM'], ['N-4', 'WH-RIM', 'extra']]))->assertOk()->json('data.rows');
        $this->assertSame(['VALID', 'VALID', 'DUPLICATE', 'INVALID'], array_column($rows, 'status'));

        $result = $this->postJson("/api/v1/app/rim-products/{$product->id}/import?mode=NEW_STOCK", ['rows' => array_map(fn ($r) => ['row' => $r['row'], 'values' => $r['values']], $rows)], $headers)->assertOk()->json('data');
        // The extra-column error is a property of the file: the values sent back hold only template columns.
        $this->assertSame(['PARTIAL', 3], [$result['status'], $result['imported']]);
        $this->assertSame([['IN_STOCK', $this->warehouse->id], ['IN_STOCK', $this->warehouse->id]], ComponentAsset::query()->whereIn('serial_number', ['N-1', 'N-2'])->get()->map(fn ($a) => [$a->current_status, $a->current_warehouse_id])->all());
        $this->getJson("/api/v1/app/rim-products/{$product->id}", $headers)->assertJsonPath('data.inventory.new_qty', 3);
    }
}
