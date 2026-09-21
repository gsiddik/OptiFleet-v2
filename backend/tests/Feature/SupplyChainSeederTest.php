<?php

namespace Tests\Feature;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Organization\Models\WarehouseBin;
use App\Domain\Organization\Models\WarehouseRack;
use App\Domain\Organization\Models\WarehouseZone;
use App\Domain\Procurement\Models\GoodsReceiptItem;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\Tire\Models\Tire;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsSeeder;
use Database\Seeders\SupplyChainSeeder;
use Tests\TestCase;

/**
 * Regression coverage for the SupplyChainSeeder Product-creation
 * correction: every Product it seeds must be database-valid AND
 * business-valid — server-generated Code, mandatory Default Storage
 * Location, an item-type-scoped Category, and a real CTI specification
 * row — produced through ProductSpecificationService/
 * DocumentNumberingService exactly as ProductController::store() would,
 * never a raw insert. Also verifies every downstream SupplyChain scenario
 * (Inventory, Procurement, Work Order parts, Tire, ComponentAsset,
 * Warranty) still resolves against the corrected Products.
 */
class SupplyChainSeederTest extends TestCase
{
    private function seedChain(): Tenant
    {
        $this->seed(MasterDataSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $this->seed(OperationsSeeder::class);
        $this->seed(SupplyChainSeeder::class);

        return Tenant::query()->where('code', 'ALPHA')->firstOrFail();
    }

    public function test_supply_chain_seeder_product_creation_is_idempotent_on_rerun(): void
    {
        $tenant = $this->seedChain();

        $codesBefore = Product::query()->where('tenant_id', $tenant->id)
            ->whereIn('sku', ['SKU-BRK-PAD', 'SKU-OIL-FLT', 'SKU-BATTERY', 'SKU-TR-29580', 'SKU-IMPACT-WR'])
            ->pluck('code', 'sku')->all();
        $countBefore = Product::query()->where('tenant_id', $tenant->id)->count();

        // A second run of SupplyChainSeeder alone must not consume a fresh
        // Item Code or create a duplicate Product for any of its SKUs —
        // the sku lookup happens before the numbering sequence is ever
        // touched (Section 14).
        $this->seed(SupplyChainSeeder::class);

        $codesAfter = Product::query()->where('tenant_id', $tenant->id)
            ->whereIn('sku', ['SKU-BRK-PAD', 'SKU-OIL-FLT', 'SKU-BATTERY', 'SKU-TR-29580', 'SKU-IMPACT-WR'])
            ->pluck('code', 'sku')->all();
        $countAfter = Product::query()->where('tenant_id', $tenant->id)->count();

        $this->assertSame($codesBefore, $codesAfter, 'Rerunning SupplyChainSeeder must not regenerate any Product Code.');
        $this->assertSame($countBefore, $countAfter, 'Rerunning SupplyChainSeeder must not duplicate any Product.');
    }

    public function test_products_use_server_generated_code_and_numbering(): void
    {
        $tenant = $this->seedChain();

        foreach (['SKU-BRK-PAD', 'SKU-OIL-FLT', 'SKU-BATTERY', 'SKU-TR-29580', 'SKU-IMPACT-WR'] as $sku) {
            $product = Product::query()->where('tenant_id', $tenant->id)->where('sku', $sku)->firstOrFail();
            $this->assertNotNull($product->numbering_configuration_version_id, "{$sku} must have a numbering configuration version.");
            $this->assertMatchesRegularExpression('/^ITM\/\d{4}\/\d{6}$/', $product->code, "{$sku}'s code must come from the numbering sequence, not be hardcoded.");
        }
    }

    public function test_products_have_a_mandatory_default_storage_location_in_the_alpha_hierarchy(): void
    {
        $tenant = $this->seedChain();

        $bin = WarehouseBin::query()->where('tenant_id', $tenant->id)->where('code', 'GENERAL')->firstOrFail();
        $rack = WarehouseRack::query()->findOrFail($bin->warehouse_rack_id);
        $zone = WarehouseZone::query()->findOrFail($rack->warehouse_zone_id);
        $this->assertSame($tenant->id, $bin->tenant_id);
        $this->assertSame($tenant->id, $rack->tenant_id);
        $this->assertSame($tenant->id, $zone->tenant_id);

        foreach (['SKU-BRK-PAD', 'SKU-OIL-FLT', 'SKU-BATTERY', 'SKU-TR-29580', 'SKU-IMPACT-WR'] as $sku) {
            $product = Product::query()->where('tenant_id', $tenant->id)->where('sku', $sku)->firstOrFail();
            $this->assertSame($bin->id, $product->default_storage_bin_id, "{$sku} must have a Default Storage Location in ALPHA's own hierarchy.");
        }
    }

    public function test_product_categories_are_item_type_scoped_to_match_their_products(): void
    {
        $tenant = $this->seedChain();

        foreach ([
            'SKU-BRK-PAD' => 'SPARE_PART', 'SKU-OIL-FLT' => 'SPARE_PART', 'SKU-BATTERY' => 'SPARE_PART',
            'SKU-TR-29580' => 'TIRE', 'SKU-IMPACT-WR' => 'TOOL',
        ] as $sku => $expectedType) {
            $product = Product::query()->where('tenant_id', $tenant->id)->where('sku', $sku)->firstOrFail();
            $category = ProductCategory::query()->findOrFail($product->product_category_id);
            $this->assertSame($expectedType, $product->product_type);
            $this->assertSame($expectedType, $category->item_type, "Category for {$sku} must be scoped to {$expectedType}.");
        }
    }

    public function test_spareparts_have_valid_specification_and_compatibility(): void
    {
        $tenant = $this->seedChain();

        foreach (['SKU-BRK-PAD', 'SKU-OIL-FLT', 'SKU-BATTERY'] as $sku) {
            $product = Product::query()->where('tenant_id', $tenant->id)->where('sku', $sku)->firstOrFail();
            $spec = $product->sparepartSpec;
            $this->assertNotNull($spec, "{$sku} must have a ProductSparepartSpec row.");
            $this->assertNotEmpty($spec->part_number);
            $this->assertNotEmpty($spec->part_type);
            $this->assertNotEmpty($product->brand);
            $this->assertGreaterThanOrEqual(1, $product->compatibilities()->count(), "{$sku} must have at least one Vehicle Compatibility.");
        }

        $battery = Product::query()->where('tenant_id', $tenant->id)->where('sku', 'SKU-BATTERY')->firstOrFail();
        $this->assertTrue((bool) $battery->track_serial_number);
    }

    public function test_tire_has_valid_specification_and_derived_values(): void
    {
        $tenant = $this->seedChain();

        $tireProduct = Product::query()->where('tenant_id', $tenant->id)->where('sku', 'SKU-TR-29580')->firstOrFail();
        $spec = $tireProduct->tireSpec;

        $this->assertNotNull($spec);
        $this->assertSame('TRUCK_BUS', $spec->vehicle_group);
        $this->assertSame('295/80 R22.5', $spec->tire_size_computed);
        $this->assertNotNull($spec->single_max_load_kg_computed);
        $this->assertNotNull($spec->dual_max_load_kg_computed);
        $this->assertNotNull($spec->max_speed_kmh_computed);
        $this->assertNotNull($spec->load_range_computed);
    }

    public function test_tool_has_valid_specification(): void
    {
        $tenant = $this->seedChain();

        $toolProduct = Product::query()->where('tenant_id', $tenant->id)->where('sku', 'SKU-IMPACT-WR')->firstOrFail();
        $spec = $toolProduct->toolSpec;

        $this->assertNotNull($spec);
        $this->assertNotNull($spec->tool_type_id);
        $this->assertTrue((bool) $spec->maintenance_required);
        $this->assertNotNull($spec->maintenance_interval_value);
    }

    public function test_downstream_supply_chain_scenarios_still_reference_the_corrected_products(): void
    {
        $tenant = $this->seedChain();

        $brakePad = Product::query()->where('tenant_id', $tenant->id)->where('sku', 'SKU-BRK-PAD')->firstOrFail();
        $oilFilter = Product::query()->where('tenant_id', $tenant->id)->where('sku', 'SKU-OIL-FLT')->firstOrFail();
        $tireProduct = Product::query()->where('tenant_id', $tenant->id)->where('sku', 'SKU-TR-29580')->firstOrFail();
        $battery = Product::query()->where('tenant_id', $tenant->id)->where('sku', 'SKU-BATTERY')->firstOrFail();

        $this->assertGreaterThan(0, StockMovement::query()->where('product_id', $brakePad->id)->count(), 'Inventory stock movements must reference the corrected brake pad.');
        $this->assertGreaterThan(0, WorkOrderPlannedPart::query()->where('product_id', $brakePad->id)->count(), 'Work Order planned part must reference the corrected brake pad.');
        $this->assertGreaterThan(0, PurchaseOrderItem::query()->where('product_id', $oilFilter->id)->count(), 'Purchase Order item must reference the corrected oil filter.');
        $this->assertGreaterThan(0, GoodsReceiptItem::query()->where('product_id', $oilFilter->id)->count(), 'Goods Receipt item must reference the corrected oil filter.');
        $this->assertSame(2, Tire::query()->where('product_id', $tireProduct->id)->count(), 'Both physical Tire instances must reference the corrected Tire product.');

        $asset = ComponentAsset::query()->where('product_id', $battery->id)->firstOrFail();
        $this->assertTrue(Warranty::query()->where('component_asset_id', $asset->id)->exists());
        $this->assertTrue(WarrantyClaim::query()->where('tenant_id', $tenant->id)->exists());
    }
}
