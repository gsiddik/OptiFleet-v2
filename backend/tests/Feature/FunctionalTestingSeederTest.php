<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Models\User;
use Brick\Math\BigDecimal;
use Database\Seeders\FunctionalTestingSeeder;
use Tests\TestCase;

/**
 * Verifies the Functional Testing Seeder's critical guarantees (Section 45):
 * idempotency, referential integrity, tenant isolation, and coverage of
 * every scenario the catalog promises. Runs the seeder twice in one test
 * to prove reruns never duplicate data.
 */
class FunctionalTestingSeederTest extends TestCase
{
    private function seedTwice(): Tenant
    {
        $this->seed(FunctionalTestingSeeder::class);
        $this->seed(FunctionalTestingSeeder::class);

        return Tenant::query()->where('code', 'FTEST')->firstOrFail();
    }

    public function test_seeder_is_idempotent_across_two_runs(): void
    {
        $tenant = $this->seedTwice();

        $this->assertSame(5, User::query()->where('email', 'like', 'ft.%@optifleet.test')->count());
        $this->assertSame(5, Vehicle::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(21, Product::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(18, WorkOrder::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(4, WorkOrderExternalInvoice::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_all_six_product_item_types_exist(): void
    {
        $tenant = $this->seedTwice();

        $types = Product::query()->where('tenant_id', $tenant->id)->distinct()->pluck('product_type')->sort()->values()->all();

        $this->assertEqualsCanonicalizing(
            ['SPARE_PART', 'CONSUMABLE', 'RIM', 'TIRE', 'TOOL', 'EQUIPMENT'],
            $types
        );
    }

    public function test_tire_coverage_includes_car_and_truck_bus(): void
    {
        $tenant = $this->seedTwice();

        $groups = ProductTireSpec::query()
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->pluck('vehicle_group')->sort()->values()->all();

        $this->assertEqualsCanonicalizing(['CAR', 'TRUCK_BUS'], $groups);
    }

    public function test_product_referential_integrity_storage_bin_and_spec_row(): void
    {
        $tenant = $this->seedTwice();

        $brakePad = Product::query()->where('tenant_id', $tenant->id)->where('name', '[TEST] Brake Pad Set (Standard)')->firstOrFail();

        $this->assertNotNull($brakePad->default_storage_bin_id);
        $this->assertNotNull($brakePad->defaultStorageBin);
        $this->assertNotNull($brakePad->sparepartSpec);
        $this->assertSame('BRK-PAD-STD-01', $brakePad->sparepartSpec->part_number);
        $this->assertGreaterThanOrEqual(1, $brakePad->compatibilities()->count());
        $this->assertStringStartsWith('SPR-BRK-', $brakePad->sku, 'SKU is server-generated, never a literal fixture code.');
        $this->assertNotNull($brakePad->component_category_id, 'Category is mandatory for a Sparepart.');
        $rule = $brakePad->compatibilities()->firstOrFail();
        $this->assertSame('[TEST] Toyota', $rule->brandMaster->name);
        $this->assertSame('[TEST] Avanza', $rule->modelMaster->name);
    }

    public function test_all_functional_test_records_belong_to_the_functional_test_tenant(): void
    {
        $tenant = $this->seedTwice();

        $this->assertSame(0, Vehicle::query()->where('registration_number', 'like', '% TST')->where('tenant_id', '!=', $tenant->id)->count());
        $this->assertSame(0, Product::query()->where('name', 'like', '[TEST]%')->where('tenant_id', '!=', $tenant->id)->count());
    }

    public function test_scheduler_coverage_around_reference_date(): void
    {
        $tenant = $this->seedTwice();

        $scheduled = WorkOrder::query()->where('tenant_id', $tenant->id)->where('status', 'SCHEDULED')->count();
        $this->assertGreaterThanOrEqual(4, $scheduled);

        $closed = WorkOrder::query()->where('tenant_id', $tenant->id)->where('status', 'CLOSED')->count();
        $this->assertGreaterThanOrEqual(1, $closed, 'Scheduler must have at least one Closed WO to verify exclusion.');
    }

    public function test_external_work_order_pre_delivered_and_delivered_scenarios_exist(): void
    {
        $tenant = $this->seedTwice();

        $this->assertTrue(
            WorkOrderExternalInvoice::query()->where('tenant_id', $tenant->id)->where('status', 'NEW_EXTERNAL_WO')->exists(),
            'Expected a pre-WAL-delivery (NEW_EXTERNAL_WO) External WO scenario.'
        );
        $this->assertTrue(
            WorkOrderExternalInvoice::query()->where('tenant_id', $tenant->id)->where('status', 'DELIVERED')->exists(),
            'Expected a WAL-delivered External WO scenario.'
        );
    }

    public function test_settlement_scenario_uses_exact_match_payment(): void
    {
        $tenant = $this->seedTwice();

        $settled = WorkOrderExternalInvoice::query()->where('tenant_id', $tenant->id)->where('status', 'PAID')->firstOrFail();

        $this->assertNotNull($settled->paid_amount);
        $this->assertNotNull($settled->vendor_invoice_amount);
        $this->assertTrue(
            BigDecimal::of((string) $settled->paid_amount)->isEqualTo(BigDecimal::of((string) $settled->vendor_invoice_amount)),
            'Settlement must be an exact-match payment.'
        );

        // The Work Order this invoice belongs to must have auto-closed.
        $workOrder = WorkOrder::query()->findOrFail($settled->work_order_id);
        $this->assertSame('CLOSED', $workOrder->status);
    }
}
