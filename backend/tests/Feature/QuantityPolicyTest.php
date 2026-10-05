<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Discrete quantity rule (owner decision): counted products take whole quantities only; products
 * in a measured unit (Capacity/Weight/Length, or the baseline Liter/Kg codes) may use fractions.
 * Enforced by the backend at the inventory engine and document-line services.
 */
class QuantityPolicyTest extends TestCase
{
    public function test_whole_number_detection_is_string_exact(): void
    {
        foreach (['5', '5.0000', '5.', 7, 12.0, '0'] as $whole) {
            $this->assertTrue(QuantityPolicy::isWhole($whole), var_export($whole, true));
        }
        foreach (['5.5', '0.0001', 2.25, '-1.5'] as $fraction) {
            $this->assertFalse(QuantityPolicy::isWhole($fraction), var_export($fraction, true));
        }
    }

    public function test_measured_units_allow_fractions_and_counted_units_do_not(): void
    {
        $this->assertTrue(QuantityPolicy::uomAllowsFraction($this->makeUom(['measure_type' => 'CAPACITY'])));
        $this->assertTrue(QuantityPolicy::uomAllowsFraction($this->makeUom(['measure_type' => 'WEIGHT'])));
        $this->assertTrue(QuantityPolicy::uomAllowsFraction($this->makeUom(['measure_type' => 'LENGTH'])));
        $this->assertFalse(QuantityPolicy::uomAllowsFraction($this->makeUom(['code' => 'LTRX-'.Str::random(3)])));
        $this->assertTrue(QuantityPolicy::uomAllowsFraction(new Uom(['code' => 'LTR'])));
        $this->assertFalse(QuantityPolicy::uomAllowsFraction($this->makeUom(['measure_type' => 'PACKAGING'])));
        $this->assertFalse(QuantityPolicy::uomAllowsFraction($this->makeUom()));
        $this->assertFalse(QuantityPolicy::uomAllowsFraction(null));
    }

    public function test_inventory_engine_rejects_fractional_counted_quantities_and_accepts_measured_ones(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $counted = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        $oil = $this->makeProduct($tenant, null, $this->makeUom(['name' => 'Liter', 'measure_type' => 'CAPACITY']), ['name' => 'Engine Oil', 'product_type' => 'CONSUMABLE']);
        $inventory = app(InventoryService::class);

        $inventory->receive($warehouse, $counted, 10, 15, 'OPENING', null, null, null);
        $inventory->receive($warehouse, $oil, 20.5, 8, 'OPENING', null, null, null);

        try {
            $inventory->receive($warehouse, $counted, 2.5, 15, 'OPENING', null, null, null);
            $this->fail('A fractional counted quantity must be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(['"Brake Pad Set" is counted in whole units — the quantity must be a whole number.'], $e->errors()['quantity']);
        }
        $this->expectException(ValidationException::class);
        try {
            $inventory->adjust($warehouse, $counted, 0.5, 'MINUS', null, 'count correction');
        } finally {
            $stock = fn ($product) => (float) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_on_hand');
            $this->assertSame(10.0, $stock($counted), 'Rejected movements post nothing.');
            $this->assertSame(20.5, $stock($oil));
        }
    }

    public function test_api_rejects_fractional_counted_quantities_on_documents(): void
    {
        $tenant = $this->makeTenant(['code' => 'QTY-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        $counted = $this->makeProduct($tenant, null, null, ['name' => 'Oil Filter']);
        $oil = $this->makeProduct($tenant, null, $this->makeUom(['name' => 'Liter', 'measure_type' => 'CAPACITY']), ['name' => 'Engine Oil', 'product_type' => 'CONSUMABLE']);
        [$user, $token] = $this->makeTenantUser($tenant, ['inventory.adjust', 'maintenance_job.manage', 'part_request.create']);
        $headers = $this->authHeaders($token);
        $service = app(WorkOrderService::class);
        $wo = $service->start($this->withApprovedWorkspace($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id)
        ))))));

        $this->postJson('/api/v1/app/inventory/adjust', ['warehouse_id' => $warehouse->id, 'product_id' => $counted->id, 'quantity' => 1.5, 'direction' => 'PLUS', 'reason' => 'x'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/app/inventory/adjust', ['warehouse_id' => $warehouse->id, 'product_id' => $counted->id, 'quantity' => 2, 'direction' => 'PLUS', 'reason' => 'x'], $headers)->assertCreated();
        $this->postJson('/api/v1/app/inventory/adjust', ['warehouse_id' => $warehouse->id, 'product_id' => $oil->id, 'quantity' => 1.5, 'direction' => 'PLUS', 'reason' => 'x'], $headers)->assertCreated();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $counted->id, 'quantity_requested' => 0.5]]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('quantity_requested');
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $oil->id, 'quantity_requested' => 4.5]]], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $counted->id, 'quantity_requested' => '3.0000']]], $headers)->assertStatus(201);
    }

    public function test_uom_payload_tells_forms_whether_decimals_are_allowed(): void
    {
        $this->assertTrue($this->makeUom(['measure_type' => 'WEIGHT'])->toArray()['allows_fractional_quantity']);
        $this->assertFalse($this->makeUom()->toArray()['allows_fractional_quantity']);
    }
}
