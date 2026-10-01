<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\StockReservationItem;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Branch;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inventory → Reservation is retired in favour of Part Requests. Historical reservations that
 * still held stock are released once (ledger entry, stock back to Available, history kept); the
 * standalone endpoints and the `inventory.reserve` permission are gone; Part Requests still run
 * REQUESTED → APPROVED → ISSUE with stock checked at Issue.
 */
class InventoryReservationRetirementTest extends TestCase
{
    private function setUpLegacy(): array
    {
        $tenant = $this->makeTenant(['code' => 'IRR-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        $inventory = app(InventoryService::class);
        $inventory->receive($warehouse, $product, 10, 50, 'OPENING', null, null, null);
        $service = app(WorkOrderService::class);
        $wo = $service->start($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null)
        )))));

        // A reservation made before the feature was retired, still holding 4 units.
        $line = WorkOrderPlannedPart::query()->create([
            'tenant_id' => $tenant->id, 'work_order_id' => $wo->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'description' => 'Brake Pad Set', 'quantity' => 4, 'planned_quantity' => 4, 'reserved_quantity' => 4, 'status' => 'RESERVED',
        ]);
        $reservation = StockReservation::query()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'work_order_id' => $wo->id, 'status' => 'RESERVED']);
        $inventory->reserve($warehouse, $product, 4, StockReservation::class, $reservation->id, null);
        StockReservationItem::query()->create(['stock_reservation_id' => $reservation->id, 'product_id' => $product->id, 'work_order_planned_part_id' => $line->id, 'requested_quantity' => 4, 'reserved_quantity' => 4]);

        return [$tenant, $warehouse, $product, $wo, $line, $reservation];
    }

    private function retirement(): object
    {
        return require database_path('migrations/2026_10_01_000009_retire_inventory_reservations.php');
    }

    private function stock($warehouse, $product): WarehouseStock
    {
        return WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
    }

    public function test_active_reservations_are_released_once_and_history_is_kept(): void
    {
        [, $warehouse, $product, , $line, $reservation] = $this->setUpLegacy();
        $cancelled = StockReservation::query()->create(['tenant_id' => $reservation->tenant_id, 'warehouse_id' => $warehouse->id, 'work_order_id' => $this->makeOtherWorkOrderId($reservation), 'status' => 'CANCELLED']);
        $this->assertSame([10.0, 4.0], [(float) $this->stock($warehouse, $product)->quantity_on_hand, (float) $this->stock($warehouse, $product)->quantity_reserved]);

        $this->retirement()->releaseActive();
        $this->retirement()->releaseActive(); // re-running is harmless

        $stock = $this->stock($warehouse, $product);
        $this->assertSame([10.0, 0.0, 10.0], [(float) $stock->quantity_on_hand, (float) $stock->quantity_reserved, $stock->quantityAvailable()], 'Held stock is available again; on-hand unchanged.');
        $release = StockMovement::query()->where('reference_type', StockReservation::class)->where('reference_id', $reservation->id)->where('movement_type', 'RELEASE_RESERVATION')->get();
        $this->assertCount(1, $release, 'Exactly one ledger release.');
        $this->assertSame('4.0000', $release->first()->quantity);

        $this->assertSame('RELEASED', $reservation->fresh()->status);
        $this->assertStringContainsString('replaced by Part Requests', $reservation->fresh()->notes);
        $this->assertSame('0.0000', $reservation->items()->first()->reserved_quantity);
        $this->assertSame('4.0000', $reservation->items()->first()->requested_quantity, 'History kept.');
        $this->assertSame(['0.0000', 'PLANNED'], [$line->fresh()->reserved_quantity, $line->fresh()->status]);
        $this->assertSame('CANCELLED', $cancelled->fresh()->status, 'Already-closed reservations are untouched.');
    }

    public function test_standalone_endpoints_and_permission_are_gone(): void
    {
        [$tenant, , , , , $reservation] = $this->setUpLegacy();
        [, $token] = $this->makeTenantUser($tenant, ['inventory.view', 'inventory.issue']);
        $headers = $this->authHeaders($token);

        $this->getJson('/api/v1/app/stock-reservations', $headers)->assertNotFound();
        $this->getJson("/api/v1/app/stock-reservations/{$reservation->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/app/stock-reservations/{$reservation->id}/cancel", [], $headers)->assertNotFound();

        $this->seed(PermissionSeeder::class);
        $this->assertFalse(DB::table('permissions')->where('name', 'inventory.reserve')->exists(), 'The baseline no longer defines inventory.reserve.');
        DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'name' => 'inventory.reserve', 'group' => 'inventory', 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->retirement()->up();
        $this->assertFalse(DB::table('permissions')->where('name', 'inventory.reserve')->exists(), 'Existing installations drop it too.');
    }

    public function test_part_requests_replace_reservation_and_check_stock_at_issue(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpLegacy();
        $this->retirement()->releaseActive();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.view', 'part_request.approve', 'part_request.issue']);
        $headers = $this->authHeaders($token);

        $id = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $product->id, 'quantity_requested' => 8]]], $headers)
            ->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->json('data.id');
        $this->postJson("/api/v1/app/part-requests/{$id}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertSame(0.0, (float) $this->stock($warehouse, $product)->quantity_reserved, 'Approval holds no stock.');

        $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $warehouse->id], $headers)->assertOk()->assertJsonPath('data.status', 'ISSUED');
        $this->assertSame(2.0, (float) $this->stock($warehouse, $product)->quantity_on_hand, 'The released 4 units were issuable again.');

        $over = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $product->id, 'quantity_requested' => 3]]], $headers)->json('data.id');
        $this->postJson("/api/v1/app/part-requests/{$over}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/part-requests/{$over}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertStatus(422)->assertJsonFragment(['message' => 'Line "Brake Pad Set": Insufficient stock: requested 3, available 2.']);
        $this->assertSame(2.0, (float) $this->stock($warehouse, $product)->quantity_on_hand, 'A rejected issue never moves stock.');
    }

    private function makeOtherWorkOrderId(StockReservation $reservation): string
    {
        $wo = WorkOrder::query()->findOrFail($reservation->work_order_id);
        $vehicle = $this->makeVehicle($wo->tenant, Branch::query()->findOrFail($wo->branch_id), $this->makeVehicleCategory(), ['default_workshop_id' => $wo->workshop_id]);

        return app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $wo->workshop_id, 'maintenance_type' => 'CORRECTIVE'], null)->id;
    }
}
