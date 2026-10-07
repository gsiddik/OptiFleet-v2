<?php

namespace Tests\Feature\StockIntegrity;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\ComponentAsset\Services\ComponentAssetException;
use App\Domain\ComponentAsset\Services\ComponentAssetService;
use App\Domain\Inventory\Models\InstallationStockExit;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Services\TireService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Installing a serialized unit takes it out of the warehouse ledger EXACTLY ONCE — whether it leaves
 * through the installation itself or through a Work Order Part Request issue that came first.
 */
class SerializedInstallationStockTest extends TestCase
{
    private array $s;

    private function scenario(int $stock = 3, string $type = 'SPARE_PART'): array
    {
        $tenant = $this->makeTenant(['code' => 'SIS-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'COMPONENT', 'TIRE', 'WORKSHOP', 'WORK_ORDER', 'MAINTENANCE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 1 SIS', 'default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Unit '.Str::random(4), 'product_type' => $type, 'track_serial_number' => true]);
        $group = $this->makeComponentGroup();
        if ($stock > 0) {
            app(InventoryService::class)->receive($warehouse, $product, $stock, 1500000, 'RECEIPT', null, null, null);
        }

        return $this->s = compact('tenant', 'branch', 'workshop', 'warehouse', 'vehicle', 'product', 'group');
    }

    private function asset(string $serial): ComponentAsset
    {
        return ComponentAsset::query()->create([
            'tenant_id' => $this->s['tenant']->id, 'product_id' => $this->s['product']->id, 'component_group_id' => $this->s['group']->id, 'serial_number' => $serial,
            'current_status' => 'IN_STOCK', 'current_warehouse_id' => $this->s['warehouse']->id, 'purchase_cost' => 1500000,
        ]);
    }

    private function onHand(): float
    {
        return (float) DB::table('warehouse_stocks')->where('warehouse_id', $this->s['warehouse']->id)->where('product_id', $this->s['product']->id)->value('quantity_on_hand');
    }

    private function issueMovements(): int
    {
        return DB::table('stock_movements')->where('warehouse_id', $this->s['warehouse']->id)->where('product_id', $this->s['product']->id)->where('movement_type', 'ISSUE')->count();
    }

    private function workOrderWithIssuedLine(float $quantity = 1.0): array
    {
        $wo = app(WorkOrderService::class)->create($this->s['vehicle'], ['workshop_id' => $this->s['workshop']->id, 'maintenance_type' => 'CORRECTIVE'], null);
        DB::table('work_orders')->where('id', $wo->id)->update(['status' => 'IN_PROGRESS']); // fixture shortcut: the state machine is not under test here
        $part = WorkOrderPlannedPart::query()->create([
            'tenant_id' => $this->s['tenant']->id, 'work_order_id' => $wo->id, 'product_id' => $this->s['product']->id, 'warehouse_id' => $this->s['warehouse']->id,
            'description' => 'Serialized part', 'quantity' => $quantity, 'planned_quantity' => $quantity, 'stock_condition' => 'NEW', 'status' => 'PLANNED',
        ]);
        app(WorkOrderPartService::class)->issue($part->fresh(), null, null);

        return [WorkOrder::query()->findOrFail($wo->id), $part->fresh()];
    }

    public function test_direct_installation_takes_the_unit_out_of_the_ledger_exactly_once(): void
    {
        $this->scenario(3);
        $asset = $this->asset('SN-1');

        $installation = app(ComponentAssetService::class)->install($asset, $this->s['vehicle'], 'ENGINE_BAY', 1000, null, null);

        $this->assertSame(2.0, $this->onHand());
        $this->assertSame(1, $this->issueMovements());
        $exit = InstallationStockExit::query()->where('installation_id', $installation->id)->firstOrFail();
        $this->assertSame('DIRECT_ISSUE', $exit->source);
        $movement = DB::table('stock_movements')->where('id', $exit->stock_movement_id)->first();
        $this->assertSame([ComponentInstallation::class, $installation->id, 'ISSUE'], [$movement->reference_type, $movement->reference_id, $movement->movement_type]);
        $this->assertSame('INSTALLED', $asset->fresh()->current_status);
    }

    public function test_issue_through_a_work_order_then_installation_does_not_deduct_again(): void
    {
        $this->scenario(3);
        $asset = $this->asset('SN-1');
        [$wo, $part] = $this->workOrderWithIssuedLine(1);
        $this->assertSame(2.0, $this->onHand());

        $installation = app(ComponentAssetService::class)->install($asset, $this->s['vehicle'], 'ENGINE_BAY', 1000, $wo->id, null);

        $this->assertSame(2.0, $this->onHand(), 'the Part Request issue already took the unit out');
        $this->assertSame(1, $this->issueMovements());
        $exit = InstallationStockExit::query()->where('installation_id', $installation->id)->firstOrFail();
        $this->assertSame(['WO_ISSUE', $part->id, null], [$exit->source, $exit->planned_part_id, $exit->stock_movement_id]);
    }

    public function test_issue_consume_then_installation_stays_consistent_and_each_issued_unit_covers_one_serial_only(): void
    {
        $this->scenario(3);
        $first = $this->asset('SN-1');
        $second = $this->asset('SN-2');
        [$wo, $part] = $this->workOrderWithIssuedLine(1);
        app(WorkOrderPartService::class)->consume($part->fresh(), null, null);
        $this->assertSame(2.0, $this->onHand(), 'consumption is a zero-effect marker');

        $components = app(ComponentAssetService::class);
        $components->install($first, $this->s['vehicle'], 'A', 1000, $wo->id, null);
        $this->assertSame(2.0, $this->onHand());

        // The issued line is used up: a second serial installed on the same Work Order leaves the warehouse itself.
        $components->install($second, $this->s['vehicle'], 'B', 1000, $wo->id, null);
        $this->assertSame(1.0, $this->onHand());
        $this->assertSame(['WO_ISSUE' => 1, 'DIRECT_ISSUE' => 1], InstallationStockExit::query()->pluck('source')->countBy()->all());
    }

    public function test_repeated_request_and_stale_model_never_move_stock_twice(): void
    {
        $this->scenario(3);
        $asset = $this->asset('SN-1');
        $stale = ComponentAsset::query()->findOrFail($asset->id);
        $components = app(ComponentAssetService::class);

        $components->install($asset, $this->s['vehicle'], 'A', 1000, null, null);
        foreach ([$asset->fresh(), $stale] as $again) { // a retry, and a caller still holding the pre-install model
            try {
                $components->install($again, $this->s['vehicle'], 'A', 1000, null, null);
                $this->fail('a second installation must be rejected');
            } catch (ComponentAssetException $e) {
                $this->assertStringContainsString('already installed', $e->getMessage());
            }
        }

        $this->assertSame(2.0, $this->onHand());
        $this->assertSame(1, $this->issueMovements());
        $this->assertSame(1, InstallationStockExit::query()->count());
        $this->assertSame(1, ComponentInstallation::query()->count());
    }

    public function test_insufficient_stock_is_rejected_and_everything_rolls_back(): void
    {
        $this->scenario(0);
        $asset = $this->asset('SN-1'); // a unit physically in the warehouse that the ledger does not hold

        $this->expectException(\App\Domain\Inventory\Services\InventoryException::class);
        try {
            app(ComponentAssetService::class)->install($asset, $this->s['vehicle'], 'A', 1000, null, null);
        } finally {
            $this->assertSame('IN_STOCK', $asset->fresh()->current_status);
            $this->assertSame(0, ComponentInstallation::query()->count());
            $this->assertSame(0, InstallationStockExit::query()->count());
            $this->assertSame(0, $this->issueMovements());
        }
    }

    public function test_a_work_order_of_another_vehicle_or_company_is_rejected_without_side_effects(): void
    {
        $this->scenario(2);
        $asset = $this->asset('SN-1');
        $other = $this->makeVehicle($this->s['tenant'], $this->s['branch'], $this->makeVehicleCategory(), ['registration_number' => 'B 2 SIS', 'default_workshop_id' => $this->s['workshop']->id]);
        $wo = app(WorkOrderService::class)->create($other, ['workshop_id' => $this->s['workshop']->id, 'maintenance_type' => 'CORRECTIVE'], null);
        $foreign = $this->makeTenant(['code' => 'SIS-X'.Str::random(3)]);

        foreach ([$wo->id, (string) Str::uuid()] as $workOrderId) {
            try {
                app(ComponentAssetService::class)->install($asset, $this->s['vehicle'], 'A', 1000, $workOrderId, null);
                $this->fail('mismatching Work Order accepted');
            } catch (\App\Domain\Inventory\Services\InventoryException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        try {
            app(ComponentAssetService::class)->install($asset, $this->makeVehicle($foreign, $this->makeBranch($foreign), $this->makeVehicleCategory(), ['registration_number' => 'B 3 SIS']), 'A', 1000, null, null);
            $this->fail('foreign vehicle accepted');
        } catch (ComponentAssetException $e) {
            $this->assertStringContainsString('another company', $e->getMessage());
        }

        $this->assertSame(2.0, $this->onHand());
        $this->assertSame('IN_STOCK', $asset->fresh()->current_status);
    }

    public function test_removal_does_not_add_stock_and_reinstalling_a_removed_unit_does_not_deduct(): void
    {
        $this->scenario(3);
        $asset = $this->asset('SN-1');
        $components = app(ComponentAssetService::class);
        $components->install($asset, $this->s['vehicle'], 'A', 1000, null, null);
        $this->assertSame(2.0, $this->onHand());

        $components->remove($asset->fresh(), 'Moved', 'REUSE', 1200, 'GOOD', null, null, null, $this->s['warehouse']->id);
        $this->assertSame(2.0, $this->onHand(), 'removal does not make the unit available stock again');
        $this->assertSame('REMOVED', $asset->fresh()->current_status);

        $second = $components->install($asset->fresh(), $this->s['vehicle'], 'B', 1300, null, null);
        $this->assertSame(2.0, $this->onHand());
        $exit = InstallationStockExit::query()->where('installation_id', $second->id)->firstOrFail();
        $this->assertSame(['NOT_LEDGERED', 'NOT_NEW_STOCK'], [$exit->source, $exit->reason]);
        $this->assertSame(1, $this->issueMovements());
    }

    public function test_a_repaired_unit_back_in_stock_is_not_deducted_again_and_is_flagged(): void
    {
        $this->scenario(3);
        $asset = $this->asset('SN-1');
        $components = app(ComponentAssetService::class);
        $components->install($asset, $this->s['vehicle'], 'A', 1000, null, null);
        $components->remove($asset->fresh(), 'Failed', 'REPAIR', 1200, 'POOR', null, null, null, $this->s['warehouse']->id);
        $repair = $components->startRepair($asset->fresh(), 'Rebuild', null, null);
        $components->completeRepair($repair, 'RETURNED_TO_SERVICE', 100000);
        $this->assertSame('IN_STOCK', $asset->fresh()->current_status);

        $installation = $components->install($asset->fresh(), $this->s['vehicle'], 'A', 1500, null, null);

        $exit = InstallationStockExit::query()->where('installation_id', $installation->id)->firstOrFail();
        $this->assertSame(['NOT_LEDGERED', 'PREVIOUSLY_INSTALLED'], [$exit->source, $exit->reason]);
        $this->assertSame(2.0, $this->onHand());
    }

    public function test_new_stock_tire_installation_follows_the_same_rule(): void
    {
        $this->scenario(3, 'TIRE');
        $tire = Tire::query()->create(['tenant_id' => $this->s['tenant']->id, 'product_id' => $this->s['product']->id, 'serial_number' => 'T-1', 'current_status' => 'IN_STOCK', 'current_warehouse_id' => $this->s['warehouse']->id]);
        $tires = app(TireService::class);

        $installation = $tires->install($tire, $this->s['vehicle'], 'FRONT_LEFT', 1000, null, null);

        $this->assertSame(2.0, $this->onHand());
        $this->assertSame('DIRECT_ISSUE', InstallationStockExit::query()->where('installation_id', $installation->id)->value('source'));
        $this->expectException(\App\Domain\Tire\Services\TireException::class);
        $tires->install($tire, $this->s['vehicle'], 'FRONT_RIGHT', 1000, null, null);
    }

    public function test_tire_issued_through_a_work_order_is_not_deducted_again_and_registration_without_warehouse_never_touches_stock(): void
    {
        $this->scenario(3, 'TIRE');
        $tires = app(TireService::class);
        $tire = Tire::query()->create(['tenant_id' => $this->s['tenant']->id, 'product_id' => $this->s['product']->id, 'serial_number' => 'T-1', 'current_status' => 'IN_STOCK', 'current_warehouse_id' => $this->s['warehouse']->id]);
        [$wo] = $this->workOrderWithIssuedLine(1);
        $this->assertSame(2.0, $this->onHand());

        $installation = $tires->install($tire, $this->s['vehicle'], 'FRONT_LEFT', 1000, $wo->id, null);
        $this->assertSame(2.0, $this->onHand());
        $this->assertSame('WO_ISSUE', InstallationStockExit::query()->where('installation_id', $installation->id)->value('source'));

        $registered = Tire::query()->create(['tenant_id' => $this->s['tenant']->id, 'product_id' => $this->s['product']->id, 'serial_number' => 'T-REG', 'current_status' => 'IN_STOCK']);
        $initial = $tires->install($registered, $this->s['vehicle'], 'FRONT_RIGHT', 1000, null, null);
        $this->assertSame(2.0, $this->onHand(), 'initial registration is not a warehouse issue');
        $this->assertSame(['NOT_LEDGERED', 'NO_WAREHOUSE'], InstallationStockExit::query()->where('installation_id', $initial->id)->get(['source', 'reason'])->map(fn ($r) => [$r->source, $r->reason])->first());
    }
}
