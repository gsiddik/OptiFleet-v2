<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\StorageRequirement;
use App\Domain\ProductMaster\Models\ToolType;
use App\Domain\Tire\Models\ProductRimSpec;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TirePlyRating;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item-Type-specific Product Excel template + import: per-type headers, template identity (Item Type
 * marker), the same validation as Create Product (classification, mandatory / conditional spec, brand /
 * model compatibility), duplicates, and persisted specifications.
 */
class ProductImportTest extends TestCase
{
    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'PIM-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'ORGANIZATION');
        [$user, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create']);
        $warehouse = $this->makeWarehouse($tenant, null, null, ['code' => 'WH-PIM']);
        $bin = $this->makeWarehouseBin($tenant, $warehouse);
        $bin->load('rack.zone');
        $path = 'WH-PIM/'.$bin->rack->zone->code.'/'.$bin->rack->code.'/'.$bin->code;
        $wty = $this->makeComponentGroup(['code' => 'CG-TYRE', 'abbreviation' => 'WTY', 'name' => 'Wheel & Tyre System']);
        ComponentCategory::query()->create(['tenant_id' => null, 'component_group_id' => $wty->id, 'code' => 'STEEL_RIM', 'name' => 'Steel Rim', 'is_system' => true, 'status' => 'ACTIVE']);
        $rimCategory = $this->makeProductCategory(['code' => 'PC-RIM-'.Str::random(3), 'item_type' => 'RIM']);
        $uom = $this->makeUom(['code' => 'PCS-'.Str::random(3)]);

        return [$tenant, $this->authHeaders($token), $path, $rimCategory, $uom, $user];
    }

    private function download(array $headers, string $itemType): XlsxReader
    {
        $content = $this->get("/api/v1/app/products/import-template?item_type={$itemType}", $headers)->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'prd');
        file_put_contents($path, $content);

        return XlsxReader::open($path);
    }

    /** A filled copy of the downloaded template (How To kept, so its Item Type marker travels with it). */
    private function filled(array $headers, string $itemType, array $rows): UploadedFile
    {
        $reader = $this->download($headers, $itemType);
        $howTo = array_map(fn ($r) => array_values(array_map(fn ($c) => $c['value'], $r)), array_values($reader->rows('How To')));
        $header = array_column($reader->rows('Fill Here')[1], 'value');
        $writer = (new XlsxWriter)->addSheet('How To', $howTo)
            ->addSheet('Fill Here', [$header, ...array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $header), $rows)]);

        return UploadedFile::fake()->createWithContent('products.xlsx', $writer->toString());
    }

    public function test_each_item_type_has_its_own_template_from_the_product_form(): void
    {
        [, $headers] = $this->scenario();
        $expect = [
            'SPARE_PART' => ['Part Number', 'Part Type', 'Vehicle Compatibility (Brand Code:Model Code)', 'Track Serial Number'],
            'CONSUMABLE' => ['Track Batch', 'Track Expiry', 'Hazardous', 'Storage Requirement Codes'],
            'RIM' => ['Rim Type', 'Diameter (inch)', 'Width (inch)', 'Bolt Holes', 'PCD (mm)', 'Track Serial Number'],
            'TIRE' => ['Vehicle Group', 'Pattern Name', 'Single Load Index', 'Speed Rating', 'Dual Load Index'],
            'TOOL' => ['Tool Type Code', 'Checkout Required', 'Calibration Required', 'Calibration Interval'],
            'EQUIPMENT' => ['Equipment Type Code', 'Inspection Required', 'Inspection Interval'],
        ];
        $all = [];
        foreach ($expect as $type => $specific) {
            $reader = $this->download($headers, $type);
            $this->assertSame(['How To', 'Fill Here'], $reader->sheetNames());
            $fill = array_column($reader->rows('Fill Here')[1], 'value');
            $this->assertSame(['Item Name', 'Product Category Code', 'UOM Code', 'Storage Bin (Warehouse/Zone/Rack/Bin)'], array_slice($fill, 0, 4));
            foreach ($specific as $h) {
                $this->assertContains($h, $fill, "{$type}: {$h}");
            }
            $this->assertSame("OPTIFLEET-PRODUCT-TEMPLATE:{$type}", $reader->rows('How To')[2][0]['value']);
            $all[$type] = $fill;
        }
        $this->assertNotContains('Rim Type', $all['TIRE']);
        $this->assertNotContains('Part Number', $all['RIM']);
        $this->get('/api/v1/app/products/import-template?item_type=BOGUS', $headers)->assertStatus(422);
    }

    public function test_rim_rows_follow_create_product_rules_and_persist_the_spec(): void
    {
        [$tenant, $headers, $bin, $category, $uom] = $this->scenario();
        $base = ['Product Category Code' => $category->code, 'UOM Code' => $uom->code, 'Storage Bin (Warehouse/Zone/Rack/Bin)' => $bin,
            'Component Group Code' => 'WTY', 'Component Category Code' => 'STEEL_RIM', 'Brand' => 'Accuride', 'Track Serial Number' => 'Yes',
            'Rim Type' => 'steel', 'Diameter (inch)' => '22.5', 'Width (inch)' => '8.25', 'Bolt Holes' => '10', 'PCD (mm)' => '335'];
        $this->makeProduct($tenant, $category, $uom, ['product_type' => 'RIM', 'name' => 'Existing Rim', 'brand' => 'Accuride']);
        $file = $this->filled($headers, 'RIM', [
            ['Item Name' => 'Steel Rim 22.5'] + $base,                                         // 2 VALID
            ['Item Name' => 'steel rim 22.5'] + $base,                                         // 3 DUPLICATE in file (name + brand)
            ['Item Name' => 'Existing Rim'] + $base,                                           // 4 DUPLICATE in database
            ['Item Name' => 'Bad Type', 'Rim Type' => 'WOOD'] + $base,                         // 5 INVALID: spec enum
            ['Item Name' => 'No Bolts', 'Bolt Holes' => ''] + $base,                           // 6 INVALID: mandatory spec
            ['Item Name' => 'Bad Group', 'Component Group Code' => 'NOPE'] + $base,            // 7 INVALID: unknown master
            ['Item Name' => 'Bad Bin', 'Storage Bin (Warehouse/Zone/Rack/Bin)' => 'X/Y'] + $base, // 8 INVALID: bin path
            ['Item Name' => 'No Brand', 'Brand' => ''] + $base,                                // 9 INVALID: brand required for RIM
        ]);
        $preview = $this->post('/api/v1/app/products/import/preview?item_type=RIM', ['file' => $file], $headers)->assertOk()->json('data');
        $this->assertSame('RIM', $preview['item_type']);
        $this->assertSame(['VALID', 'DUPLICATE', 'DUPLICATE', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID'], array_column($preview['rows'], 'status'));

        $result = $this->postJson('/api/v1/app/products/import?item_type=RIM', ['rows' => array_map(fn ($r) => ['row' => $r['row'], 'values' => $r['values']], $preview['rows'])], $headers)->assertOk()->json('data');
        $this->assertSame(['PARTIAL', 1], [$result['status'], $result['imported']]);
        $product = Product::query()->where('name', 'Steel Rim 22.5')->sole();
        $this->assertSame(['RIM', 'Accuride', true], [$product->product_type, $product->brand, (bool) $product->track_serial_number]);
        $this->assertNotEmpty($product->code);
        $this->assertNotEmpty($product->sku, 'Item Code and SKU are generated as in Create Product');
        $spec = ProductRimSpec::query()->findOrFail($product->id);
        $this->assertSame(['STEEL', '22.50', '8.25', 10, '335.00'], [$spec->rim_type, (string) $spec->diameter_inch, (string) $spec->width_inch, (int) $spec->bolt_holes, (string) $spec->pcd_mm]);
    }

    public function test_a_template_of_another_item_type_is_rejected(): void
    {
        [, $headers] = $this->scenario();
        $tireTemplate = $this->filled($headers, 'TIRE', [['Item Name' => 'x']]);
        $this->post('/api/v1/app/products/import/preview?item_type=RIM', ['file' => $tireTemplate], $headers)
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'This file is a template for Item Type TIRE; it cannot be imported as RIM. Download the RIM template.');
        // Without a context the template identifies its own Item Type.
        $this->post('/api/v1/app/products/import/preview', ['file' => $this->filled($headers, 'TIRE', [['Item Name' => 'x']])], $headers)->assertOk()->assertJsonPath('data.item_type', 'TIRE');
        // A workbook without the How To marker is not a product template.
        $plain = UploadedFile::fake()->createWithContent('p.xlsx', (new XlsxWriter)->addSheet('How To', [['x']])->addSheet('Fill Here', [['Item Name']])->toString());
        $this->post('/api/v1/app/products/import/preview?item_type=RIM', ['file' => $plain], $headers)->assertStatus(422)->assertJsonValidationErrors('file');
        // The import endpoint also checks the marker-less rows against the requested Item Type's columns.
        $this->postJson('/api/v1/app/products/import', ['rows' => [['row' => 2, 'values' => ['name' => 'x']]]], $headers)->assertStatus(422)->assertJsonValidationErrors('item_type');
    }

    public function test_sparepart_compatibility_and_dynamic_spec_rules(): void
    {
        [$tenant, $headers, $bin, , $uom] = $this->scenario();
        $group = $this->makeComponentGroup(['code' => 'CG-BRK-'.Str::random(3), 'abbreviation' => 'BRX', 'name' => 'Brake']);
        ComponentCategory::query()->create(['tenant_id' => null, 'component_group_id' => $group->id, 'code' => 'PAD', 'name' => 'Pad', 'is_system' => true, 'status' => 'ACTIVE']);
        $category = $this->makeProductCategory(['code' => 'PC-SP-'.Str::random(3), 'item_type' => 'SPARE_PART']);
        $brand = VehicleBrand::query()->create(['tenant_id' => $tenant->id, 'code' => 'HINO', 'name' => 'Hino', 'is_system' => false, 'status' => 'ACTIVE']);
        VehicleModel::query()->create(['tenant_id' => $tenant->id, 'vehicle_brand_id' => $brand->id, 'code' => 'H500', 'name' => 'Hino 500', 'is_system' => false, 'status' => 'ACTIVE']);
        $base = ['Product Category Code' => $category->code, 'UOM Code' => $uom->code, 'Storage Bin (Warehouse/Zone/Rack/Bin)' => $bin, 'Component Group Code' => 'BRX',
            'Component Category Code' => 'PAD', 'Brand' => 'Bendix', 'Track Serial Number' => 'No', 'Part Number' => 'DB1', 'Part Type' => 'aftermarket'];
        $file = $this->filled($headers, 'SPARE_PART', [
            ['Item Name' => 'Pad A', 'Vehicle Compatibility (Brand Code:Model Code)' => 'hino:h500'] + $base,   // VALID
            ['Item Name' => 'Pad B', 'Vehicle Compatibility (Brand Code:Model Code)' => 'HINO:ELF'] + $base,    // INVALID: model not of brand
            ['Item Name' => 'Pad C', 'Vehicle Compatibility (Brand Code:Model Code)' => ''] + $base,            // INVALID: compatibility mandatory
            ['Item Name' => 'Pad D', 'Vehicle Compatibility (Brand Code:Model Code)' => 'HINO:H500', 'Warranty Period' => 'two'] + $base, // INVALID: integer
        ]);
        $rows = $this->post('/api/v1/app/products/import/preview', ['file' => $file], $headers)->assertOk()->json('data.rows');
        $this->assertSame(['VALID', 'INVALID', 'INVALID', 'INVALID'], array_column($rows, 'status'));
        $result = $this->postJson('/api/v1/app/products/import?item_type=SPARE_PART', ['rows' => [['row' => 2, 'values' => $rows[0]['values']]]], $headers)->assertOk()->json('data');
        $this->assertSame('SUCCESS', $result['status']);
        $product = Product::query()->where('name', 'Pad A')->sole();
        $this->assertSame(1, $product->compatibilities()->count());
        $this->assertSame('DB1', $product->sparepartSpec->part_number);
    }

    public function test_tool_consumable_and_tire_conditional_specs_persist(): void
    {
        [, $headers, $bin, , $uom] = $this->scenario();
        $common = ['UOM Code' => $uom->code, 'Storage Bin (Warehouse/Zone/Rack/Bin)' => $bin];

        // TOOL: Calibration Interval is required when Calibration Required = Yes.
        ToolType::query()->create(['tenant_id' => null, 'code' => 'HAND_T', 'name' => 'Hand tool', 'is_system' => true, 'status' => 'ACTIVE']);
        $toolCategory = $this->makeProductCategory(['code' => 'PC-TL-'.Str::random(3), 'item_type' => 'TOOL']);
        $tool = ['Product Category Code' => $toolCategory->code, 'Track Serial Number' => 'Yes', 'Tool Type Code' => 'HAND_T', 'Checkout Required' => 'Ya', 'Maintenance Required' => 'No'] + $common;
        $rows = $this->post('/api/v1/app/products/import/preview', ['file' => $this->filled($headers, 'TOOL', [
            ['Item Name' => 'Torque Wrench', 'Calibration Required' => 'Yes', 'Calibration Interval' => '12', 'Calibration Interval Unit' => 'months'] + $tool,
            ['Item Name' => 'Torque Wrench 2', 'Calibration Required' => 'Yes'] + $tool,
            ['Item Name' => 'Torque Wrench 3', 'Calibration Required' => 'Maybe'] + $tool,
        ])], $headers)->assertOk()->json('data.rows');
        $this->assertSame(['VALID', 'INVALID', 'INVALID'], array_column($rows, 'status'));
        $this->assertStringContainsString('Calibration Interval is required', implode(' ', $rows[1]['errors']));
        $this->postJson('/api/v1/app/products/import?item_type=TOOL', ['rows' => [['row' => 2, 'values' => $rows[0]['values']]]], $headers)->assertOk()->assertJsonPath('data.status', 'SUCCESS');
        $spec = Product::query()->where('name', 'Torque Wrench')->sole()->toolSpec;
        $this->assertSame([true, true, 12, 'MONTHS'], [(bool) $spec->checkout_required, (bool) $spec->calibration_required, (int) $spec->calibration_interval_value, $spec->calibration_interval_unit]);

        // CONSUMABLE: Storage Requirement is required when Hazardous = Yes.
        $group = $this->makeComponentGroup(['code' => 'CG-ENG-'.Str::random(3), 'abbreviation' => 'ENX', 'name' => 'Engine']);
        ComponentCategory::query()->create(['tenant_id' => null, 'component_group_id' => $group->id, 'code' => 'OIL', 'name' => 'Oil', 'is_system' => true, 'status' => 'ACTIVE']);
        StorageRequirement::query()->create(['tenant_id' => null, 'code' => 'FLAM', 'name' => 'Flammable', 'is_system' => true, 'status' => 'ACTIVE']);
        $oilCategory = $this->makeProductCategory(['code' => 'PC-OIL-'.Str::random(3), 'item_type' => 'CONSUMABLE']);
        $oil = ['Product Category Code' => $oilCategory->code, 'Component Group Code' => 'ENX', 'Component Category Code' => 'OIL', 'Track Batch' => 'Yes', 'Track Expiry' => 'No'] + $common;
        $rows = $this->post('/api/v1/app/products/import/preview', ['file' => $this->filled($headers, 'CONSUMABLE', [
            ['Item Name' => 'Oil A', 'Hazardous' => 'Yes', 'Storage Requirement Codes' => 'flam'] + $oil,
            ['Item Name' => 'Oil B', 'Hazardous' => 'Yes'] + $oil,
            ['Item Name' => 'Oil C', 'Hazardous' => 'No', 'Storage Requirement Codes' => 'NOPE'] + $oil,
        ])], $headers)->assertOk()->json('data.rows');
        $this->assertSame(['VALID', 'INVALID', 'INVALID'], array_column($rows, 'status'));
        $this->postJson('/api/v1/app/products/import?item_type=CONSUMABLE', ['rows' => [['row' => 2, 'values' => $rows[0]['values']]]], $headers)->assertOk()->assertJsonPath('data.status', 'SUCCESS');
        $this->assertSame(['FLAM'], Product::query()->where('name', 'Oil A')->sole()->consumableSpec->storageRequirements->pluck('code')->all());

        // TIRE: Dual Load Index and Ply Rating are required for TRUCK_BUS.
        $wtyTire = ComponentCategory::query()->create(['tenant_id' => null, 'component_group_id' => ComponentGroup::query()->where('code', 'CG-TYRE')->value('id'), 'code' => 'TIRE', 'name' => 'Tire', 'is_system' => true, 'status' => 'ACTIVE']);
        TireLoadIndex::query()->create(['code' => '152', 'max_load_single_kg' => 3550, 'status' => 'ACTIVE']);
        TireLoadIndex::query()->create(['code' => '148', 'max_load_single_kg' => 3150, 'status' => 'ACTIVE']);
        TireSpeedRating::query()->create(['code' => 'M', 'max_speed_kmh' => 130, 'status' => 'ACTIVE']);
        TirePlyRating::query()->create(['code' => '16PR', 'is_system' => true, 'status' => 'ACTIVE']);
        $tireCategory = $this->makeProductCategory(['code' => 'PC-TR-'.Str::random(3), 'item_type' => 'TIRE']);
        $tire = ['Product Category Code' => $tireCategory->code, 'Component Group Code' => 'CG-TYRE', 'Component Category Code' => $wtyTire->code, 'Brand' => 'Bridgestone',
            'Vehicle Group' => 'TRUCK_BUS', 'Pattern Name' => 'R150', 'Width (mm)' => '295', 'Aspect Ratio (%)' => '80', 'Construction Type' => 'RADIAL',
            'Rim Diameter (inch)' => '22.5', 'Tire Type' => 'TUBELESS', 'Single Load Index' => '152', 'Speed Rating' => 'M'] + $common;
        $rows = $this->post('/api/v1/app/products/import/preview', ['file' => $this->filled($headers, 'TIRE', [
            ['Item Name' => 'R150 A', 'Dual Load Index' => '148', 'Ply Rating' => '16PR'] + $tire,
            ['Item Name' => 'R150 B'] + $tire,
        ])], $headers)->assertOk()->json('data.rows');
        $this->assertSame(['VALID', 'INVALID'], array_column($rows, 'status'));
        $this->assertStringContainsString('Dual Load Index is required', implode(' ', $rows[1]['errors']));
        $this->postJson('/api/v1/app/products/import?item_type=TIRE', ['rows' => [['row' => 2, 'values' => $rows[0]['values']]]], $headers)->assertOk()->assertJsonPath('data.status', 'SUCCESS');
        $this->assertSame('TRUCK_BUS', Product::query()->where('name', 'R150 A')->sole()->tireSpec->vehicle_group);
    }
}
