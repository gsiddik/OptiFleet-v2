<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireOperation;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Tire\Models\UsedTireStock;
use App\Domain\Tire\Models\UsedTireStockMovement;
use App\Domain\Tire\Services\TireFactsService;
use App\Domain\Tire\Services\TireInventoryService;
use App\Domain\Tire\Services\UsedTireStockService;
use App\Domain\Tire\Support\TireOperationStatus;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Services\WorkOrderException;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tire Operations: Replacement / Rotation / Inspection planned on the vehicle's mapped wheels
 * configuration, executed through the Work Order created with them.
 */
class TireOperationTest extends TestCase
{
    private const OPS = '/api/v1/app/tire-operations';

    private const PERMISSIONS = [
        'tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'wheel_configuration.map_vehicle',
        'work_order.view', 'work_order.create', 'work_order.update', 'work_order.cancel',
        'part_request.view', 'part_request.approve', 'part_request.issue', 'inventory.issue',
    ];

    /** Passenger car 1.1 + 1 spare: positions 1FL1 1FR1 1RL1 1RR1 S1, every position with a registered tire. */
    private function scenario(array $permissions = self::PERMISSIONS): array
    {
        $tenant = $this->makeTenant(['code' => 'TOP-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), [
            'vehicle_type' => 'Car', 'axle_count' => 2, 'wheel_count' => 5, 'registration_number' => 'B 9 TOP', 'default_workshop_id' => $workshop->id, 'current_odometer' => 10000,
        ]);
        [$user, $token] = $this->makeTenantUser($tenant, $permissions);
        $headers = $this->authHeaders($token);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Bridgestone Ecopia']);
        app(InventoryService::class)->receive($warehouse, $product, 10, 100, 'OPENING', null, null, null);

        [, $adminToken] = $this->makeTenantUser($tenant, self::PERMISSIONS);
        $admin = $this->authHeaders($adminToken);
        $masterId = $this->postJson('/api/v1/app/wheel-configuration-masters', ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => [1], 'spare_tires' => 1], $admin)->assertStatus(201)->json('data.master.id');
        $this->putJson("/api/v1/app/wheel-configuration-masters/{$masterId}/vehicle-mappings", ['add_vehicle_ids' => [$vehicle->id], 'remove_vehicle_ids' => []], $admin)->assertOk();
        foreach (['1FL1', '1FR1', '1RL1', '1RR1', 'S1'] as $i => $code) {
            $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration/tires", [
                'position_code' => $code, 'installed_date' => '2026-09-01', 'installed_time' => '07:30', 'installation_km' => '10000',
                'product_id' => $product->id, 'serial_number' => "OLD-{$code}", 'tread_depth_mm' => '9',
            ], $admin)->assertStatus(201);
        }

        return compact('tenant', 'branch', 'workshop', 'warehouse', 'vehicle', 'user', 'headers', 'product', 'admin');
    }

    private function stockTire($s, string $serial, ?string $productId = null): Tire
    {
        return Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $productId ?? $s['product']->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
    }

    private function payload($s, string $type, array $extra = []): array
    {
        return array_merge(['vehicle_id' => $s['vehicle']->id, 'operation_type' => $type, 'operated_date' => '2026-10-01', 'operated_time' => '09:15', 'odometer' => '15000'], $extra);
    }

    private function startWorkOrder(string $workOrderId): WorkOrder
    {
        $service = app(WorkOrderService::class);

        return $service->start($this->withApprovedWorkspace($service->schedule($service->assign($service->approve($service->submit(WorkOrder::query()->findOrFail($workOrderId)))))));
    }

    private function onHand($s): float
    {
        return (float) WarehouseStock::query()->where('warehouse_id', $s['warehouse']->id)->where('product_id', $s['product']->id)->value('quantity_on_hand');
    }

    public function test_replacement_reserves_through_part_request_and_consume_installs_the_serials(): void
    {
        $s = $this->scenario();
        $new1 = $this->stockTire($s, 'NEW-001');
        $new2 = $this->stockTire($s, 'NEW-002');
        $this->stockTire($s, 'NEW-003');

        $created = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [
            ['position_code' => '1FL1', 'replacement_tire_id' => $new1->id],
            ['position_code' => '1FR1', 'replacement_tire_id' => $new2->id],
        ]]), $s['headers'])->assertStatus(201);
        $created->assertJsonPath('data.status', TireOperationStatus::NEW)->assertJsonPath('data.config_code', '1.1')
            ->assertJsonPath('data.work_order.status', 'DRAFT')->assertJsonPath('data.part_request.status', 'REQUESTED')
            ->assertJsonPath('data.operated_time', '09:15');
        $woNumber = $created->json('data.work_order.wo_number');
        $this->assertNotEmpty($woNumber); // central numbering service
        $wo = WorkOrder::query()->findOrFail($created->json('data.work_order.id'));
        $this->assertSame(['CORRECTIVE', '15000.00'], [$wo->maintenance_type, (string) $wo->current_odometer]);

        // Work Order → Issuance & Return: the Tire Product, Qty = number of "Replacing With" serials.
        $request = WorkOrderPartRequest::query()->with('items')->findOrFail($created->json('data.part_request.id'));
        $this->assertSame([$s['product']->id, 2.0], [$request->items[0]->product_id, (float) $request->items[0]->quantity_requested]);
        $this->assertCount(1, $request->items);
        $this->assertSame($created->json('data.id'), $request->tire_operation_id);
        $this->assertSame(10.0, $this->onHand($s), 'Reserve never moves stock.');

        $this->startWorkOrder($wo->id);
        $this->assertSame(TireOperationStatus::IN_PROGRESS, $this->getJson(self::OPS.'/'.$created->json('data.id'), $s['headers'])->json('data.status'));

        // Qty == serial count: a partial approval is refused.
        $this->postJson("/api/v1/app/part-requests/{$request->id}/approve", ['approved_quantities' => [$request->items[0]->id => 1]], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/part-requests/{$request->id}/approve", [], $s['headers'])->assertOk();
        $this->postJson("/api/v1/app/part-requests/{$request->id}/issue", ['warehouse_id' => $s['warehouse']->id], $s['headers'])->assertOk();
        $this->assertSame(8.0, $this->onHand($s), 'Issue deducts stock (existing inventory flow).');

        // Consume 1 → the first position is replaced; consume the rest → all installed.
        $part = $request->fresh('items')->items[0]->planned_part_id;
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part}/consume", ['quantity' => 1], $s['headers'])->assertOk();
        $this->assertSame(['INSTALLED', '1FL1'], [$new1->fresh()->current_status, $new1->fresh()->current_position]);
        $this->assertSame('IN_STOCK', $new2->fresh()->current_status);
        $this->assertSame('REMOVED', Tire::query()->where('serial_number', 'OLD-1FL1')->value('current_status'));
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part}/consume", ['quantity' => 1], $s['headers'])->assertOk();
        $this->assertSame(['INSTALLED', '1FR1'], [$new2->fresh()->current_status, $new2->fresh()->current_position]);

        // The old tire waits in Used Tire Management (REMOVED) and is not offered as a replacement
        // until it is back in stock; the removal and installation carry the Tire Operations date/time.
        $old = Tire::query()->where('serial_number', 'OLD-1FL1')->firstOrFail();
        $this->assertSame('2026-10-01 02:15:00', $old->removals()->latest('removed_at')->first()->removed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 02:15:00', $new1->installations()->first()->installed_at->utc()->format('Y-m-d H:i:s'));
        $candidates = fn () => collect($this->getJson("/api/v1/app/tire-operations/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->assertOk()->json('data'));
        $this->assertFalse($candidates()->pluck('serial_number')->contains('OLD-1FL1'));
        $old->update(['current_status' => 'REUSE', 'current_warehouse_id' => $s['warehouse']->id]); // e.g. after its inspection in Used Tire Management
        $this->assertSame('REUSE', $candidates()->firstWhere('serial_number', 'OLD-1FL1')['source']);
        $this->assertSame('NEW_STOCK', $candidates()->firstWhere('serial_number', 'NEW-003')['source']);

        // Tire Detail → Installed shows the new serials.
        $installed = collect($this->getJson("/api/v1/app/tire-products/{$s['product']->id}/inventory?category=INSTALLED", $s['headers'])->json('data'))->pluck('serial_number');
        $this->assertTrue($installed->contains('NEW-001') && $installed->contains('NEW-002') && ! $installed->contains('OLD-1FL1'));

        // Completing the Work Order completes the operation.
        $service = app(WorkOrderService::class);
        $service->complete($service->submitToQc($wo->fresh()));
        $this->assertSame(TireOperationStatus::COMPLETED, $this->getJson(self::OPS.'/'.$created->json('data.id'), $s['headers'])->json('data.status'));

        // The replacing tire's Usage Time starts at the replacement's date/time (2026-10-01 09:15):
        // an inspection on 2026-10-03 09:15 gives 48 h. The old tire keeps its own 721.75 h.
        $inspection = $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['operated_date' => '2026-10-03', 'odometer' => '15500', 'items' => [['position_code' => '1FL1']]]), $s['headers'])->assertStatus(201);
        $service->complete($service->submitToQc($this->startWorkOrder($inspection->json('data.work_order.id'))));
        $hours = app(TireInventoryService::class)->usageHours([$new1->id, $old->id]);
        $this->assertSame(['48.00', '721.75'], [$hours[$new1->id], $hours[$old->id]]);
    }

    /** A REUSE tire counted in a warehouse's used tire quantity (as after its inspection approval). */
    private function reuseTire($s, string $serial, ?string $warehouseId = null): Tire
    {
        $tire = $this->stockTire($s, $serial);
        $tire->update(['current_status' => 'REUSE', 'current_warehouse_id' => $warehouseId ?? $s['warehouse']->id]);
        app(UsedTireStockService::class)->receive($tire, $warehouseId ?? $s['warehouse']->id, 'INSPECTION_RECEIPT', null, null, null);

        return $tire->fresh();
    }

    private function usedOnHand($s, ?string $warehouseId = null): int
    {
        return (int) UsedTireStock::query()->where('warehouse_id', $warehouseId ?? $s['warehouse']->id)->where('product_id', $s['product']->id)->value('quantity_on_hand');
    }

    /** @return array{0: WorkOrder, 1: WorkOrderPartRequest} the started Work Order and its approved request */
    private function approvedReplacement($s, array $items): array
    {
        $created = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => $items]), $s['headers'])->assertStatus(201);
        $request = WorkOrderPartRequest::query()->findOrFail($created->json('data.part_request.id'));
        $wo = $this->startWorkOrder($created->json('data.work_order.id'));
        $this->postJson("/api/v1/app/part-requests/{$request->id}/approve", [], $s['headers'])->assertOk();

        return [$wo, $request];
    }

    /** A REUSE serial is issued through the Part Request from the used tire quantity (a USED line), like new stock. */
    public function test_replacement_with_a_reuse_tire_is_issued_from_the_used_tire_quantity(): void
    {
        $s = $this->scenario();
        $new = $this->stockTire($s, 'NEW-R1');
        $reuse = $this->reuseTire($s, 'USED-R1');
        $removed = $this->stockTire($s, 'RMV-R1');
        $removed->update(['current_status' => 'REMOVED']);
        $nowhere = $this->stockTire($s, 'USED-NOWH');
        $nowhere->update(['current_status' => 'REUSE']);
        $this->assertSame(1, $this->usedOnHand($s));

        $candidates = collect($this->getJson("/api/v1/app/tire-operations/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->keyBy('serial_number');
        $this->assertSame(['NEW_STOCK', 'REUSE'], [$candidates['NEW-R1']['source'], $candidates['USED-R1']['source']]);
        $this->assertSame($s['warehouse']->name, $candidates['USED-R1']['warehouse']);
        $this->assertFalse($candidates->has('RMV-R1'));
        $this->assertFalse($candidates->has('USED-NOWH'), 'A REUSE tire in no warehouse cannot be issued.');
        foreach ([$removed, $nowhere] as $invalid) {
            $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $invalid->id]]]), $s['headers'])
                ->assertStatus(422)->assertJsonValidationErrors('items');
        }

        [$wo, $request] = $this->approvedReplacement($s, [
            ['position_code' => '1FL1', 'replacement_tire_id' => $new->id],
            ['position_code' => '1FR1', 'replacement_tire_id' => $reuse->id],
        ]);
        $lines = $request->fresh('items')->items->keyBy('stock_condition');
        $this->assertSame([1.0, 1.0], [(float) $lines['NEW']->quantity_requested, (float) $lines['USED']->quantity_requested]);
        $this->assertSame('USED', $lines['USED']->plannedPart->stock_condition);

        $this->postJson("/api/v1/app/part-requests/{$request->id}/issue", ['warehouse_id' => $s['warehouse']->id], $s['headers'])->assertOk();
        $this->assertSame([9.0, 0], [$this->onHand($s), $this->usedOnHand($s)], 'Each line left its own quantity.');
        $this->assertSame('REUSE', $reuse->fresh()->current_status, 'Issued, installed at Consume.');

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$lines['USED']->planned_part_id}/consume", ['quantity' => 1], $s['headers'])->assertOk();
        $this->assertSame(['INSTALLED', '1FR1'], [$reuse->fresh()->current_status, $reuse->fresh()->current_position]);
        $this->assertSame('IN_STOCK', $new->fresh()->current_status);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$lines['NEW']->planned_part_id}/consume", ['quantity' => 1], $s['headers'])->assertOk();

        $service = app(WorkOrderService::class);
        $service->complete($service->submitToQc($wo->fresh()));
        $this->assertSame(['REMOVED', 'REMOVED'], [Tire::query()->where('serial_number', 'OLD-1FL1')->value('current_status'), Tire::query()->where('serial_number', 'OLD-1FR1')->value('current_status')]);
        $this->assertSame(['INSPECTION_RECEIPT', 'ISSUE'], UsedTireStockMovement::query()->where('tire_id', $reuse->id)->orderBy('sequence')->pluck('movement_type')->all());
        $this->assertSame([9.0, 0], [$this->onHand($s), $this->usedOnHand($s)]);
    }

    public function test_a_reuse_replacement_outside_its_allowed_positions_is_warned_not_blocked(): void
    {
        $s = $this->scenario();
        $reuse = $this->reuseTire($s, 'USED-POS');
        // The inspection that returned it to stock limited it to the rear positions.
        TireUsedInspection::query()->create([
            'tenant_id' => $s['tenant']->id, 'tire_id' => $reuse->id, 'status' => TireUsedInspection::APPROVED, 'tire_status_before' => 'REMOVED',
            'inspected_at' => now(), 'tire_snapshot' => [], 'identity_status' => 'COMPLETE', 'internal_inspected' => 'YES', 'wear_pattern' => 'EVEN',
            'bulge_separation' => 'NONE', 'cord_exposure' => 'NONE', 'sidewall_condition' => 'NORMAL', 'bead_condition' => 'NORMAL',
            'inner_liner_condition' => 'NORMAL', 'run_flat_overheat' => 'NO', 'leak_foreign_object' => 'NO', 'previous_repair' => 'NONE',
            'age_chemical' => 'NONE', 'casing_compliance' => 'MEETS', 'recommendation' => 'REUSE', 'reasons' => [], 'follow_ups' => [], 'variables' => [],
            'thresholds' => ['application_limits' => ['positions' => ['1RL1', '1RR1']]], 'final_disposition' => 'REUSE', 'approved_at' => now(),
        ]);

        $candidate = collect($this->getJson("/api/v1/app/tire-operations/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->firstWhere('serial_number', 'USED-POS');
        $this->assertSame(['1RL1', '1RR1'], $candidate['usage_restrictions']['positions']);

        $created = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $reuse->id]]]), $s['headers'])->assertStatus(201);
        $this->assertCount(1, $created->json('data.warnings'));
        $this->assertStringContainsString('restricted to position(s) 1RL1, 1RR1', $created->json('data.warnings.0'));
        $this->assertStringContainsString('planned for 1FL1', $created->json('data.warnings.0'));

        $edited = $this->putJson(self::OPS.'/'.$created->json('data.id'), $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1RL1', 'replacement_tire_id' => $reuse->id]]]), $s['headers'])->assertOk();
        $this->assertSame([], $edited->json('data.warnings'));
    }

    public function test_a_used_line_is_issued_only_from_the_warehouse_holding_the_reuse_tire(): void
    {
        $s = $this->scenario();
        $other = $this->makeWarehouse($s['tenant'], $s['branch'], $s['workshop'], ['code' => 'WH-OTHER']);
        $new = $this->stockTire($s, 'NEW-W1');
        $reuse = $this->reuseTire($s, 'USED-W2', $other->id);
        [, $request] = $this->approvedReplacement($s, [
            ['position_code' => '1FL1', 'replacement_tire_id' => $new->id],
            ['position_code' => '1FR1', 'replacement_tire_id' => $reuse->id],
        ]);

        $this->postJson("/api/v1/app/part-requests/{$request->id}/issue", ['warehouse_id' => $s['warehouse']->id], $s['headers'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Line "Bridgestone Ecopia": Used tire USED-W2 is not in this warehouse\'s used stock.']);
        $this->assertSame([10.0, 1], [$this->onHand($s), $this->usedOnHand($s, $other->id)], 'All-or-nothing: nothing moved.');
        $this->assertSame('APPROVED', $request->fresh()->status);
    }

    public function test_an_issued_used_tire_returned_unused_goes_back_to_used_stock_or_on_hold(): void
    {
        $s = $this->scenario([...self::PERMISSIONS, 'inventory.return', 'part_return.process']);
        $good = $this->reuseTire($s, 'USED-G1');
        $faulty = $this->reuseTire($s, 'USED-F1');
        [$wo, $request] = $this->approvedReplacement($s, [
            ['position_code' => '1FL1', 'replacement_tire_id' => $good->id],
            ['position_code' => '1FR1', 'replacement_tire_id' => $faulty->id],
        ]);
        $this->postJson("/api/v1/app/part-requests/{$request->id}/issue", ['warehouse_id' => $s['warehouse']->id], $s['headers'])->assertOk();
        $this->assertSame(0, $this->usedOnHand($s));
        $part = $request->fresh('items')->items[0]->planned_part_id;

        $return = fn (string $condition) => $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part}/return", ['quantity' => 1, 'condition' => $condition], $s['headers'])->assertOk();
        $return('UNUSED_NEW');
        $first = WorkOrderPartReturn::query()->latest('created_at')->firstOrFail();
        $this->postJson("/api/v1/app/part-returns/{$first->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 1], $s['headers'])->assertOk();
        $this->assertSame(1, $this->usedOnHand($s));
        $this->assertSame(10.0, $this->onHand($s), 'New stock is untouched by a used tire return.');
        $restocked = Tire::query()->whereIn('id', [$good->id, $faulty->id])->get()->first(fn (Tire $t) => app(UsedTireStockService::class)->isCounted($t->id));
        $this->assertSame(['REUSE', $s['warehouse']->id], [$restocked->current_status, $restocked->current_warehouse_id]);

        $return('UNUSED_FAULTY');
        $second = WorkOrderPartReturn::query()->where('id', '!=', $first->id)->latest('created_at')->firstOrFail();
        $this->postJson("/api/v1/app/part-returns/{$second->id}/process", ['actual_condition' => 'UNUSED_FAULTY', 'received_quantity' => 1], $s['headers'])->assertOk();
        $held = Tire::query()->whereIn('id', [$good->id, $faulty->id])->where('id', '!=', $restocked->id)->firstOrFail();
        $this->assertSame(['HOLD', $s['warehouse']->id], [$held->current_status, $held->current_warehouse_id], 'Faulty: re-inspected in Used Tire Management.');
        $this->assertSame(1, $this->usedOnHand($s));
    }

    public function test_a_reuse_tire_installed_or_scrapped_directly_leaves_the_used_tire_quantity(): void
    {
        $s = $this->scenario([...self::PERMISSIONS, 'tire.scrap', 'inventory.view']);
        $installed = $this->reuseTire($s, 'USED-D1');
        $scrapped = $this->reuseTire($s, 'USED-D2');
        $this->assertSame(2, $this->usedOnHand($s));
        // Warehouse Stock → Used Tires: quantity and the serials counted in it.
        $row = $this->getJson('/api/v1/app/inventory/used-tires', $s['headers'])->assertOk()->json('data.0');
        $this->assertSame([2, $s['warehouse']->id, ['USED-D1', 'USED-D2']], [$row['quantity_on_hand'], $row['warehouse']['id'], array_column($row['serials'], 'serial_number')]);

        $spare = Tire::query()->where('serial_number', 'OLD-S1')->firstOrFail();
        $this->postJson("/api/v1/app/tires/{$spare->id}/remove", ['removal_reason' => 'swap', 'disposition' => 'REUSE', 'odometer' => 15000], $s['headers'])->assertSuccessful();
        $this->postJson("/api/v1/app/tires/{$installed->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'S1', 'odometer' => 15000], $s['headers'])->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$scrapped->id}/scrap", ['reason' => 'damaged in storage'], $s['headers'])->assertSuccessful();

        $this->assertSame(0, $this->usedOnHand($s));
        $this->assertSame([], $this->getJson('/api/v1/app/inventory/used-tires', $s['headers'])->assertOk()->json('data'), 'Empty rows are hidden.');
        $this->assertSame(['INSPECTION_RECEIPT', 'INSTALL'], UsedTireStockMovement::query()->where('tire_id', $installed->id)->orderBy('sequence')->pluck('movement_type')->all());
        $this->assertSame(['INSPECTION_RECEIPT', 'SCRAP'], UsedTireStockMovement::query()->where('tire_id', $scrapped->id)->orderBy('sequence')->pluck('movement_type')->all());
    }

    public function test_work_order_cannot_complete_before_the_replacement_is_consumed(): void
    {
        $s = $this->scenario();
        $new = $this->stockTire($s, 'NEW-010');
        $id = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1RL1', 'replacement_tire_id' => $new->id]]]), $s['headers'])->assertStatus(201)->json('data.work_order.id');
        $service = app(WorkOrderService::class);
        $wo = $service->submitToQc($this->startWorkOrder($id));

        $this->expectException(WorkOrderException::class);
        $service->complete($wo);
    }

    public function test_rotation_pairs_are_validated_and_swapped_when_the_work_order_completes(): void
    {
        $s = $this->scenario();
        $pairs = fn (array $p) => $this->postJson(self::OPS, $this->payload($s, 'ROTATION', ['rotation_pairs' => $p]), $s['headers']);
        $pairs([['from' => '1FL1', 'to' => '1FL1']])->assertStatus(422)->assertJsonValidationErrors('rotation_pairs');
        $pairs([['from' => '1FL1', 'to' => '1FR1'], ['from' => '1FR1', 'to' => '1RL1']])->assertStatus(422);
        $pairs([['from' => '1FL1', 'to' => '1FR1'], ['from' => '1FR1', 'to' => '1FL1']])->assertStatus(422);
        $pairs([['from' => '1FL1', 'to' => '9ZZ9']])->assertStatus(422);
        $this->assertSame(0, TireOperation::query()->count());

        $op = $pairs([['from' => '1FL1', 'to' => '1RR1'], ['from' => '1FR1', 'to' => 'S1']])->assertStatus(201);
        $this->assertSame([1, 1, 2, 2], collect($op->json('data.items'))->pluck('pair_number')->all());
        // Rotation never reserves a tire product in Issuance & Return.
        $this->assertNull($op->json('data.part_request'));
        $this->assertSame(0, WorkOrderPartRequest::query()->where('work_order_id', $op->json('data.work_order.id'))->count());
        $op->assertJsonPath('data.configuration.config_code', '1.1')->assertJsonPath('data.configuration.spare_tires', 1);
        // Positions in an open operation cannot be planned again.
        $pairs([['from' => '1FL1', 'to' => '1RL1']])->assertStatus(422);

        $service = app(WorkOrderService::class);
        $service->complete($service->submitToQc($this->startWorkOrder($op->json('data.work_order.id'))));
        $this->assertSame('1RR1', Tire::query()->where('serial_number', 'OLD-1FL1')->value('current_position'));
        $this->assertSame('1FL1', Tire::query()->where('serial_number', 'OLD-1RR1')->value('current_position'));
        $this->assertSame('S1', Tire::query()->where('serial_number', 'OLD-1FR1')->value('current_position'));
        // Usage: installed at 10000, rotated at 15000 → 5000 km so far.
        $tireId = Tire::query()->where('serial_number', 'OLD-1FL1')->value('id');
        $this->assertSame('5000.00', app(TireInventoryService::class)->usage([$tireId])[$tireId]);
    }

    public function test_inspection_selects_positions_records_tread_and_accumulates_usage(): void
    {
        $s = $this->scenario();
        $first = $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['items' => [
            ['position_code' => '1FL1', 'tread_depth_mm' => '7.5'],
            ['position_code' => 'S1'],
        ]]), $s['headers'])->assertStatus(201);
        $first->assertJsonPath('data.work_order.status', 'DRAFT')->assertJsonPath('data.part_request', null);
        $this->assertSame('INSPECTION', WorkOrder::query()->find($first->json('data.work_order.id'))->maintenance_type);
        $service = app(WorkOrderService::class);
        $service->complete($service->submitToQc($this->startWorkOrder($first->json('data.work_order.id'))));

        $tire = Tire::query()->where('serial_number', 'OLD-1FL1')->first();
        $spare = Tire::query()->where('serial_number', 'OLD-S1')->first();
        $facts = app(TireFactsService::class)->facts([$tire->id, $spare->id], 'Asia/Jakarta');
        // Usage Time: Last Known Installation 2026-09-01 07:30 → operation 2026-10-01 09:15 = 721.75 h.
        $this->assertSame(['7.50', '5000.00', '721.75'], [(string) $facts[$tire->id]['last_tread_depth_mm'], $facts[$tire->id]['usage_km'], $facts[$tire->id]['usage_hours']]);
        // No measurement entered for S1: the earlier reading (registration 9 mm) is kept, never overwritten with null.
        $this->assertSame('9.00', (string) $facts[$spare->id]['last_tread_depth_mm']);
        $this->assertSame(['TIRE_OPERATION', '2026-10-01', '09:15'], [$facts[$tire->id]['last_operation_source'], $facts[$tire->id]['last_operation_date'], $facts[$tire->id]['last_operation_time']]);

        // Second operation: usage accumulates the delta (15000 → 18250.5), it does not restart.
        $second = $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['operated_date' => '2026-10-02', 'odometer' => '18250.5', 'items' => [['position_code' => '1FL1', 'tread_depth_mm' => '7.25']]]), $s['headers'])->assertStatus(201);
        $service->complete($service->submitToQc($this->startWorkOrder($second->json('data.work_order.id'))));
        $facts = app(TireFactsService::class)->facts([$tire->id], 'Asia/Jakarta');
        // … and Usage Time adds the 24 h since the previous operation.
        $this->assertSame(['8250.50', '7.25', '745.75'], [$facts[$tire->id]['usage_km'], (string) $facts[$tire->id]['last_tread_depth_mm'], $facts[$tire->id]['usage_hours']]);
        // A reading below the last recorded KM is refused.
        $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['operated_date' => '2026-10-02', 'odometer' => '17000', 'items' => [['position_code' => '1FL1']]]), $s['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('odometer');
        // So is a date/time before the last operation on the tire (Usage Time would go back).
        $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['operated_date' => '2026-10-02', 'operated_time' => '09:14', 'odometer' => '19000', 'items' => [['position_code' => '1FL1']]]), $s['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('operated_date');
        // Positions whose tires have no later event are not affected by that floor.
        $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['operated_date' => '2026-09-15', 'odometer' => '19000', 'items' => [['position_code' => '1RR1']]]), $s['headers'])->assertStatus(201);
    }

    public function test_serial_detail_shows_position_usage_and_tread_against_the_reference(): void
    {
        $s = $this->scenario();
        $s['product']->update(['reference_tread_depth_mm' => '8.00']);
        $tire = Tire::query()->where('serial_number', 'OLD-1FL1')->firstOrFail();

        // Before any operation: registered at 10000 km with 9 mm — no usage yet, tread above reference.
        $installed = $this->getJson("/api/v1/app/tires/{$tire->id}", $s['headers'])->assertOk()->json('data.installed');
        $this->assertSame(['1FL1', 'B 9 TOP', null, null, '9.00', '8.00', false], [
            $installed['position_code'], $installed['vehicle']['registration_number'], $installed['usage_km'], $installed['usage_hours'],
            (string) $installed['current_tread_depth_mm'], (string) $installed['reference_tread_depth_mm'], $installed['tread_below_reference'],
        ]);
        $this->assertSame(['1.1', [1], [1], 1], [$installed['configuration']['config_code'], $installed['configuration']['front_axles'], $installed['configuration']['rear_axles'], $installed['configuration']['spare_tires']]);

        // An inspection measures 7.95 mm at 15000 km: usage appears, tread drops below the reference.
        $service = app(WorkOrderService::class);
        $op = $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['items' => [['position_code' => '1FL1', 'tread_depth_mm' => '7.95']]]), $s['headers'])->assertStatus(201);
        $service->complete($service->submitToQc($this->startWorkOrder($op->json('data.work_order.id'))));
        $installed = $this->getJson("/api/v1/app/tires/{$tire->id}", $s['headers'])->json('data.installed');
        $this->assertSame(['5000.00', '721.75', '7.95', true], [$installed['usage_km'], $installed['usage_hours'], (string) $installed['current_tread_depth_mm'], $installed['tread_below_reference']]);

        // A later operation without a measurement accumulates usage but keeps the measured tread.
        $op = $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['odometer' => '16000', 'items' => [['position_code' => '1FL1']]]), $s['headers'])->assertStatus(201);
        $service->complete($service->submitToQc($this->startWorkOrder($op->json('data.work_order.id'))));
        $installed = $this->getJson("/api/v1/app/tires/{$tire->id}", $s['headers'])->json('data.installed');
        $this->assertSame(['6000.00', '7.95'], [$installed['usage_km'], (string) $installed['current_tread_depth_mm']]);

        // A tire in stock has no installed block.
        $this->assertNull($this->getJson('/api/v1/app/tires/'.$this->stockTire($s, 'NEW-ONE')->id, $s['headers'])->assertOk()->json('data.installed'));
    }

    public function test_edit_keeps_the_part_request_in_sync_and_never_changes_the_vehicle(): void
    {
        $s = $this->scenario();
        [$a, $b, $c] = [$this->stockTire($s, 'NEW-A'), $this->stockTire($s, 'NEW-B'), $this->stockTire($s, 'NEW-C')];
        $op = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $a->id]]]), $s['headers'])->assertStatus(201);
        $id = $op->json('data.id');

        $other = $this->makeVehicle($s['tenant'], $s['branch'], $this->makeVehicleCategory());
        $this->putJson(self::OPS."/{$id}", $this->payload($s, 'REPLACEMENT', ['vehicle_id' => $other->id, 'items' => [['position_code' => '1FL1', 'replacement_tire_id' => $a->id]]]), $s['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $edited = $this->putJson(self::OPS."/{$id}", $this->payload($s, 'REPLACEMENT', ['odometer' => '16000', 'items' => [
            ['position_code' => '1FL1', 'replacement_tire_id' => $b->id],
            ['position_code' => '1RL1', 'replacement_tire_id' => $c->id],
        ]]), $s['headers'])->assertOk();
        $edited->assertJsonPath('data.odometer', '16000.00')->assertJsonCount(2, 'data.items');
        $request = WorkOrderPartRequest::query()->with('items')->findOrFail($op->json('data.part_request.id'));
        $this->assertSame(2.0, (float) $request->items[0]->quantity_requested);
        $this->assertSame('16000.00', (string) WorkOrder::query()->find($op->json('data.work_order.id'))->current_odometer);
        // The Work Order tab shows the same edited data.
        $this->getJson('/api/v1/app/work-orders/'.$op->json('data.work_order.id').'/tire-operation', $s['headers'])->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.odometer', '16000.00')->assertJsonPath('data.items.0.replacement_tire.serial_number', 'NEW-B');
        // NEW-A is free again.
        $this->assertContains('NEW-A', collect($this->getJson(self::OPS."/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->pluck('serial_number')->all());

        // Changing to an inspection cancels the replacement request.
        $this->putJson(self::OPS."/{$id}", $this->payload($s, 'INSPECTION', ['items' => [['position_code' => '1FL1']]]), $s['headers'])->assertOk();
        $this->assertSame('CANCELLED', $request->fresh()->status);
    }

    public function test_cancel_cancels_the_work_order_atomically_and_releases_the_serials(): void
    {
        $s = $this->scenario();
        $new = $this->stockTire($s, 'NEW-X');
        $op = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $new->id]]]), $s['headers'])->assertStatus(201);

        $this->postJson(self::OPS.'/'.$op->json('data.id').'/cancel', ['reason' => 'Wrong vehicle'], $s['headers'])->assertOk()
            ->assertJsonPath('data.status', TireOperationStatus::CANCELLED)->assertJsonPath('data.can_edit', false)->assertJsonPath('data.can_cancel', false);
        $this->assertSame('CANCELLED', WorkOrder::query()->find($op->json('data.work_order.id'))->status);
        $this->assertSame('CANCELLED', WorkOrderPartRequest::query()->find($op->json('data.part_request.id'))->status);
        $this->postJson(self::OPS.'/'.$op->json('data.id').'/cancel', [], $s['headers'])->assertStatus(422);
        $this->putJson(self::OPS.'/'.$op->json('data.id'), $this->payload($s, 'INSPECTION', ['items' => [['position_code' => '1FL1']]]), $s['headers'])->assertStatus(422);
        // The serial and the position are free for a new operation.
        $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $new->id]]]), $s['headers'])->assertStatus(201);
    }

    public function test_cancel_is_refused_once_the_replacement_tires_are_approved(): void
    {
        $s = $this->scenario();
        $new = $this->stockTire($s, 'NEW-Y');
        $op = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $new->id]]]), $s['headers'])->assertStatus(201);
        $this->startWorkOrder($op->json('data.work_order.id'));
        $this->postJson('/api/v1/app/part-requests/'.$op->json('data.part_request.id').'/approve', [], $s['headers'])->assertOk();

        $this->getJson(self::OPS.'/'.$op->json('data.id'), $s['headers'])->assertJsonPath('data.can_cancel', false);
        $this->postJson(self::OPS.'/'.$op->json('data.id').'/cancel', [], $s['headers'])->assertStatus(422);
        $this->assertSame('IN_PROGRESS', WorkOrder::query()->find($op->json('data.work_order.id'))->status);
    }

    public function test_cancelling_the_work_order_directly_cancels_the_operation(): void
    {
        $s = $this->scenario();
        $new = $this->stockTire($s, 'NEW-Z');
        $op = $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $new->id]]]), $s['headers'])->assertStatus(201);
        app(WorkOrderService::class)->cancel(WorkOrder::query()->find($op->json('data.work_order.id')));

        $this->getJson(self::OPS.'/'.$op->json('data.id'), $s['headers'])->assertJsonPath('data.status', TireOperationStatus::CANCELLED);
        $this->assertSame('CANCELLED', WorkOrderPartRequest::query()->find($op->json('data.part_request.id'))->status);
        $this->assertContains('NEW-Z', collect($this->getJson(self::OPS."/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->pluck('serial_number')->all());
    }

    public function test_validation_rules(): void
    {
        $s = $this->scenario();
        $other = $this->makeProduct($s['tenant'], null, null, ['product_type' => 'TIRE']);
        $wrongProduct = $this->stockTire($s, 'OTHER-PRODUCT', $other->id);
        $new = $this->stockTire($s, 'NEW-V');
        $scrapped = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'SCRAPPED-1', 'current_status' => 'SCRAPPED']);
        $post = fn (array $p) => $this->postJson(self::OPS, $p, $s['headers']);

        // Vehicle without a Wheels Configuration.
        $unmapped = $this->makeVehicle($s['tenant'], $s['branch'], $this->makeVehicleCategory(), ['default_workshop_id' => $s['workshop']->id]);
        $post($this->payload($s, 'INSPECTION', ['vehicle_id' => $unmapped->id, 'items' => [['position_code' => '1FL1']]]))
            ->assertStatus(422)->assertJsonPath('errors.vehicle_id.0', 'This vehicle does not have a Wheels Configuration. Please map a Wheels Configuration first.');
        $this->getJson("/api/v1/app/vehicles/{$unmapped->id}/tire-operation-context", $s['headers'])->assertOk()->assertJsonPath('data.mapping', null);

        $post($this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $wrongProduct->id]]]))->assertStatus(422);
        $post($this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $scrapped->id]]]))->assertStatus(422);
        $post($this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1']]]))->assertStatus(422);
        $post($this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $new->id], ['position_code' => '1FR1', 'replacement_tire_id' => $new->id]]]))->assertStatus(422);
        $post($this->payload($s, 'INSPECTION', ['operated_date' => now()->addDays(2)->format('Y-m-d'), 'items' => [['position_code' => '1FL1']]]))->assertStatus(422)->assertJsonValidationErrors('operated_date');
        $post($this->payload($s, 'INSPECTION', ['odometer' => '-5', 'items' => [['position_code' => '1FL1']]]))->assertStatus(422)->assertJsonValidationErrors('odometer');
        $post($this->payload($s, 'INSPECTION', ['odometer' => '', 'items' => [['position_code' => '1FL1']]]))->assertStatus(422)->assertJsonValidationErrors('odometer');
        $post($this->payload($s, 'INSPECTION', ['items' => []]))->assertStatus(422);

        // A held serial cannot be chosen twice.
        $post($this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $new->id]]]))->assertStatus(201);
        $post($this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FR1', 'replacement_tire_id' => $new->id]]]))->assertStatus(422);
        $this->assertNotContains('NEW-V', collect($this->getJson(self::OPS."/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->pluck('serial_number')->all());

        // Context: every position (spare included) with its tire card and the open operation.
        $context = $this->getJson("/api/v1/app/vehicles/{$s['vehicle']->id}/tire-operation-context", $s['headers'])->assertOk();
        $context->assertJsonPath('data.mapping.config_code', '1.1')->assertJsonCount(5, 'data.positions');
        $fl = collect($context->json('data.positions'))->firstWhere('position_code', '1FL1');
        $this->assertSame(['OLD-1FL1', 'REPLACEMENT'], [$fl['tire']['serial_number'], $fl['open_operation']['operation_type']]);
    }

    public function test_permissions_scope_and_list(): void
    {
        $s = $this->scenario();
        [, $inspectorToken] = $this->makeTenantUser($s['tenant'], ['tire.view', 'tire.inspect', 'work_order.create', 'work_order.view']);
        $inspector = $this->authHeaders($inspectorToken);
        $new = $this->stockTire($s, 'NEW-P');
        $this->postJson(self::OPS, $this->payload($s, 'REPLACEMENT', ['items' => [['position_code' => '1FL1', 'replacement_tire_id' => $new->id]]]), $inspector)->assertForbidden();
        $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['items' => [['position_code' => '1FL1']]]), $inspector)->assertStatus(201);
        [, $noWo] = $this->makeTenantUser($s['tenant'], ['tire.view', 'tire.inspect']);
        $this->postJson(self::OPS, $this->payload($s, 'INSPECTION', ['items' => [['position_code' => '1FR1']]]), $this->authHeaders($noWo))->assertForbidden();

        $list = $this->getJson(self::OPS, $s['headers'])->assertOk();
        $list->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.operation_type', 'INSPECTION')->assertJsonPath('data.0.status', 'NEW')
            ->assertJsonPath('data.0.vehicle.registration_number', 'B 9 TOP')->assertJsonPath('data.0.items.0.position_code', '1FL1')
            ->assertJsonPath('data.0.can_edit', true)->assertJsonPath('data.0.can_cancel', true);
        $this->assertNotEmpty($list->json('data.0.work_order.wo_number'));
        $this->getJson(self::OPS.'?status=CANCELLED', $s['headers'])->assertJsonPath('meta.total', 0);

        // Another tenant sees nothing and cannot open it.
        $other = $this->makeTenant(['code' => 'TOX-'.Str::random(4)]);
        $this->grantModule($other, 'TIRE');
        [, $otherToken] = $this->makeTenantUser($other, self::PERMISSIONS);
        $this->getJson(self::OPS, $this->authHeaders($otherToken))->assertJsonPath('meta.total', 0);
        $this->getJson(self::OPS.'/'.$list->json('data.0.id'), $this->authHeaders($otherToken))->assertNotFound();
        // A user scoped to another branch cannot see the vehicle's operations.
        [, $scoped] = $this->makeTenantUser($s['tenant'], ['tire.view'], ['BRANCH' => $this->makeBranch($s['tenant'])->id]);
        $this->getJson(self::OPS, $this->authHeaders($scoped))->assertJsonPath('meta.total', 0);
        $this->getJson(self::OPS.'/'.$list->json('data.0.id'), $this->authHeaders($scoped))->assertForbidden();
    }

    public function test_status_is_derived_from_the_work_order(): void
    {
        $this->assertSame('NEW', TireOperationStatus::derive(null, 'DRAFT'));
        $this->assertSame('NEW', TireOperationStatus::derive(null, 'SCHEDULED'));
        $this->assertSame('IN_PROGRESS', TireOperationStatus::derive(null, 'IN_PROGRESS'));
        $this->assertSame('IN_PROGRESS', TireOperationStatus::derive(null, 'QC_PENDING'));
        $this->assertSame('COMPLETED', TireOperationStatus::derive(null, 'CLOSED'));
        $this->assertSame('CANCELLED', TireOperationStatus::derive(null, 'REJECTED'));
        $this->assertSame('CANCELLED', TireOperationStatus::derive(now(), 'DRAFT'));
    }
}
