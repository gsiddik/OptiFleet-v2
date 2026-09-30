<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderRemovedComponent;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * New part returned unused -> Return (numbered) -> Returned Parts Processing -> stock only on
 * acceptance; removed components -> Used Sparepart Processing. The two never mix.
 */
class ReturnProcessingTest extends TestCase
{
    private const PERMISSIONS = [
        'inventory.return', 'maintenance_job.manage', 'part_return.view', 'part_return.process',
        'used_part.view', 'used_part.inspect',
    ];

    private function setUpIssued(float $qty = 5): array
    {
        $tenant = $this->makeTenant(['code' => 'RTN-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        app(InventoryService::class)->receive($warehouse, $product, 20, 15, 'OPENING', null, null, null);

        $service = app(WorkOrderService::class);
        $wo = $service->start($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null)
        )))));
        $part = $this->issueThroughPartRequest($wo, $product, $qty, $warehouse);
        [$user, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS);

        return [$tenant, $warehouse, $product, $wo, $part, $this->authHeaders($token), $user];
    }

    private function returnPart($wo, $part, float $qty, string $condition, array $headers): WorkOrderPartReturn
    {
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", ['quantity' => $qty, 'condition' => $condition], $headers)->assertOk();

        return WorkOrderPartReturn::query()->where('work_order_planned_part_id', $part->id)->orderByDesc('return_number')->firstOrFail();
    }

    private function onHand($warehouse, $product): float
    {
        return (float) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_on_hand');
    }

    public function test_return_list_shows_numbered_new_part_returns_with_the_mandatory_columns(): void
    {
        [, , $product, $wo, $part, $headers] = $this->setUpIssued();
        $first = $this->returnPart($wo, $part, 1, 'UNUSED_NEW', $headers);
        $second = $this->returnPart($wo, $part, 2, 'UNUSED_FAULTY', $headers);
        app(WorkOrderPartService::class)->consume($part, 1); // installed, so its old counterpart can be removed
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", ['product_id' => $product->id, 'quantity' => 1, 'condition' => 'GOOD'], $headers)->assertStatus(201);

        $this->assertMatchesRegularExpression('#^RTN/\d{4}/000001$#', $first->return_number);
        $this->assertMatchesRegularExpression('#^RTN/\d{4}/000002$#', $second->return_number);

        $rows = $this->getJson('/api/v1/app/part-returns', $headers)->assertOk()->json('data');
        $this->assertCount(2, $rows, 'Removed components never appear in Return.');
        $row = collect($rows)->firstWhere('id', $first->id);
        $this->assertSame($first->return_number, $row['return_number']);
        $this->assertSame($wo->wo_number, $row['work_order']['wo_number']);
        $this->assertSame('Brake Pad Set', $row['product']['name']);
        $this->assertSame('1.0000', $row['quantity']);
        $this->assertSame('PENDING_PROCESSING', $row['disposition_status']);

        $this->getJson("/api/v1/app/part-returns/{$first->id}", $headers)->assertOk()->assertJsonPath('data.return_number', $first->return_number);
    }

    public function test_accepting_a_matching_return_restocks_exactly_once(): void
    {
        [, $warehouse, $product, $wo, $part, $headers, $inspector] = $this->setUpIssued(5);
        $return = $this->returnPart($wo, $part, 3, 'UNUSED_NEW', $headers);
        $this->assertSame(15.0, $this->onHand($warehouse, $product), 'Return itself posts no stock.');

        $this->postJson("/api/v1/app/part-returns/{$return->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 3, 'notes' => 'Sealed box'], $headers)
            ->assertOk()->assertJsonPath('data.disposition_status', 'RESTOCKED')->assertJsonPath('data.inspection_result', 'MATCH')
            ->assertJsonPath('data.inspector.name', $inspector->name);
        $this->assertSame(18.0, $this->onHand($warehouse, $product));

        $this->postJson("/api/v1/app/part-returns/{$return->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 3], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'This return has already been processed (RESTOCKED).');
        $this->assertSame(18.0, $this->onHand($warehouse, $product));
        $movements = StockMovement::query()->where('reference_type', WorkOrderPartReturn::class)->where('reference_id', $return->id)->get();
        $this->assertCount(1, $movements);
        $this->assertSame([ 'RETURN', 3.0], [$movements->first()->movement_type, (float) $movements->first()->quantity]);
        $this->assertSame($movements->first()->id, $return->fresh()->stock_movement_id);
    }

    public function test_faulty_or_short_returns_are_mismatches_and_faulty_never_reaches_stock(): void
    {
        [, $warehouse, $product, $wo, $part, $headers] = $this->setUpIssued(5);

        // Declared New Good, found faulty: quarantined, no stock.
        $faulty = $this->returnPart($wo, $part, 2, 'UNUSED_NEW', $headers);
        $this->postJson("/api/v1/app/part-returns/{$faulty->id}/process", ['actual_condition' => 'UNUSED_FAULTY', 'received_quantity' => 2], $headers)
            ->assertOk()->assertJsonPath('data.disposition_status', 'QUARANTINED')->assertJsonPath('data.inspection_result', 'MISMATCH');
        $this->assertSame(15.0, $this->onHand($warehouse, $product));

        // Declared 3, only 2 physically came back: only what arrived is restocked.
        $short = $this->returnPart($wo, $part, 3, 'UNUSED_NEW', $headers);
        $this->postJson("/api/v1/app/part-returns/{$short->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 4], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/part-returns/{$short->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 2], $headers)
            ->assertOk()->assertJsonPath('data.inspection_result', 'MISMATCH')->assertJsonPath('data.accepted_quantity', '2.0000');
        $this->assertSame(17.0, $this->onHand($warehouse, $product));

        $this->postJson("/api/v1/app/part-returns/{$short->id}/process", ['actual_condition' => 'USED_GOOD', 'received_quantity' => 1], $headers)->assertStatus(422);
    }

    public function test_quarantined_return_is_routed_to_a_follow_up_disposition_without_touching_stock(): void
    {
        [$tenant, $warehouse, $product, $wo, $part, $headers, $user] = $this->setUpIssued(5);
        $routed = [];
        foreach (WorkOrderPartReturn::FAULTY_DISPOSITIONS as $disposition) {
            $return = $this->returnPart($wo, $part, 1, 'UNUSED_FAULTY', $headers);
            $this->postJson("/api/v1/app/part-returns/{$return->id}/route", ['disposition' => $disposition], $headers)
                ->assertStatus(422)->assertJsonPath('message', 'Only a quarantined return can be routed to a disposition (current status: PENDING_PROCESSING).');
            $this->postJson("/api/v1/app/part-returns/{$return->id}/process", ['actual_condition' => 'UNUSED_FAULTY', 'received_quantity' => 1], $headers)
                ->assertOk()->assertJsonPath('data.disposition_status', 'QUARANTINED');

            $this->postJson("/api/v1/app/part-returns/{$return->id}/route", ['disposition' => $disposition, 'reason' => "Send to {$disposition}"], $headers)
                ->assertOk()->assertJsonPath('data.disposition_status', $disposition)
                ->assertJsonPath('data.disposition_reason', "Send to {$disposition}")->assertJsonPath('data.router.name', $user->name);
            $this->assertNotNull($return->fresh()->routed_at);
            $routed[] = $return;
        }

        // Routing never returns a faulty part to available stock, and a routed return is not re-routed.
        $this->assertSame(15.0, $this->onHand($warehouse, $product));
        $this->assertSame(0, StockMovement::query()->where('reference_type', WorkOrderPartReturn::class)->whereIn('reference_id', collect($routed)->pluck('id'))->count());
        $this->postJson("/api/v1/app/part-returns/{$routed[0]->id}/route", ['disposition' => 'SCRAP'], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'Only a quarantined return can be routed to a disposition (current status: WARRANTY_CLAIM).');
        $this->postJson("/api/v1/app/part-returns/{$routed[0]->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 1], $headers)->assertStatus(422);
        $this->assertSame(15.0, $this->onHand($warehouse, $product));

        $this->getJson('/api/v1/app/part-returns?status=REPAIR', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $routed[1]->id);
    }

    public function test_routing_rejects_restocked_returns_invalid_dispositions_and_unpermitted_users(): void
    {
        [$tenant, $warehouse, $product, $wo, $part, $headers] = $this->setUpIssued(5);
        $good = $this->returnPart($wo, $part, 1, 'UNUSED_NEW', $headers);
        $this->postJson("/api/v1/app/part-returns/{$good->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 1], $headers)->assertOk();
        $this->postJson("/api/v1/app/part-returns/{$good->id}/route", ['disposition' => 'SCRAP'], $headers)->assertStatus(422);
        $this->assertSame('RESTOCKED', $good->fresh()->disposition_status);

        $faulty = $this->returnPart($wo, $part, 1, 'UNUSED_FAULTY', $headers);
        $this->postJson("/api/v1/app/part-returns/{$faulty->id}/process", ['actual_condition' => 'UNUSED_FAULTY', 'received_quantity' => 1], $headers)->assertOk();
        foreach (['RESTOCKED', 'REUSE', 'SELL_ELIGIBLE', ''] as $invalid) {
            $this->postJson("/api/v1/app/part-returns/{$faulty->id}/route", ['disposition' => $invalid], $headers)->assertStatus(422)->assertJsonValidationErrors('disposition');
        }

        [, $viewer] = $this->makeTenantUser($tenant, ['part_return.view']);
        $this->postJson("/api/v1/app/part-returns/{$faulty->id}/route", ['disposition' => 'SCRAP'], $this->authHeaders($viewer))->assertForbidden();
        $this->assertSame('QUARANTINED', $faulty->fresh()->disposition_status);
        $this->assertSame(16.0, $this->onHand($warehouse, $product), 'Only the good return was restocked.');

        $other = $this->makeTenant(['code' => 'RTNR-'.Str::random(4)]);
        $this->grantModule($other, 'INVENTORY');
        [, $foreign] = $this->makeTenantUser($other, self::PERMISSIONS);
        $this->postJson("/api/v1/app/part-returns/{$faulty->id}/route", ['disposition' => 'SCRAP'], $this->authHeaders($foreign))->assertNotFound();
        $this->assertSame('QUARANTINED', WorkOrderPartReturn::withoutGlobalScopes()->findOrFail($faulty->id)->disposition_status);
    }

    public function test_return_permissions_and_tenant_isolation(): void
    {
        [$tenant, , , $wo, $part, $headers] = $this->setUpIssued();
        $return = $this->returnPart($wo, $part, 1, 'UNUSED_NEW', $headers);

        [, $viewer] = $this->makeTenantUser($tenant, ['part_return.view']);
        $this->getJson('/api/v1/app/part-returns', $this->authHeaders($viewer))->assertOk();
        $this->postJson("/api/v1/app/part-returns/{$return->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 1], $this->authHeaders($viewer))->assertForbidden();
        [, $nobody] = $this->makeTenantUser($tenant, ['work_order.view']);
        $this->getJson('/api/v1/app/part-returns', $this->authHeaders($nobody))->assertForbidden();

        $other = $this->makeTenant(['code' => 'RTNX-'.Str::random(4)]);
        $this->grantModule($other, 'INVENTORY');
        [, $foreign] = $this->makeTenantUser($other, self::PERMISSIONS);
        $this->getJson("/api/v1/app/part-returns/{$return->id}", $this->authHeaders($foreign))->assertNotFound();
        $this->postJson("/api/v1/app/part-returns/{$return->id}/process", ['actual_condition' => 'UNUSED_NEW', 'received_quantity' => 1], $this->authHeaders($foreign))->assertNotFound();
    }

    public function test_removed_component_goes_to_used_sparepart_processing_and_never_to_return(): void
    {
        [, $warehouse, $product, $wo, $part, $headers, $mechanic] = $this->setUpIssued();
        app(WorkOrderPartService::class)->consume($part, 2);
        $componentId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $product->id, 'quantity' => 2, 'condition' => 'FAULTY',
        ], $headers)->assertStatus(201)->json('data.id');

        $rows = collect($this->getJson('/api/v1/app/used-part-returns', $headers)->assertOk()->json('data'));
        $row = $rows->firstWhere('work_order_removed_component_id', $componentId);
        $this->assertNotNull($row, 'Recording a removal queues it in Used Sparepart Processing.');
        $this->assertSame(['REMOVED_COMPONENT', 'PENDING_RETURN', 'USED_FAULTY', '2.0000'], [$row['return_source'], $row['disposition_status'], $row['condition'], $row['quantity']]);
        $this->assertSame($wo->wo_number, $row['work_order']['wo_number']);
        $this->assertNotNull($row['work_order']['vehicle']['registration_number']);
        $this->assertSame($mechanic->id, $row['removed_component']['removed_by']);
        $this->assertNotNull($row['removed_component']['removed_at']);
        $this->assertNull($rows->firstWhere('return_source', 'NEW_PART'));

        // A NEW_PART return is not reachable through Used Sparepart Processing and vice versa.
        $this->getJson("/api/v1/app/part-returns/{$row['id']}", $headers)->assertNotFound();

        // Cannot be inspected before it physically reaches a warehouse.
        $this->postJson("/api/v1/app/used-part-returns/{$row['id']}/inspect", ['accepted_quantity' => 2, 'condition' => 'USED_FAULTY'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/used-part-returns/{$row['id']}/receive", ['warehouse_id' => $warehouse->id], $headers)
            ->assertOk()->assertJsonPath('data.disposition_status', 'PENDING_INSPECTION')->assertJsonPath('data.warehouse_id', $warehouse->id);
        $this->assertSame('RETURNED', WorkOrderRemovedComponent::query()->findOrFail($componentId)->status);
        $this->postJson("/api/v1/app/used-part-returns/{$row['id']}/receive", ['warehouse_id' => $warehouse->id], $headers)->assertStatus(422);
        $this->assertSame(1, WorkOrderPartReturn::query()->where('work_order_removed_component_id', $componentId)->count(), 'Never queued twice.');
        $this->assertSame(15.0, $this->onHand($warehouse, $product), 'An old component never becomes available stock on receipt.');
    }

    public function test_deleting_a_pending_removal_removes_its_processing_record(): void
    {
        [, , $product, $wo, $part, $headers] = $this->setUpIssued();
        app(WorkOrderPartService::class)->consume($part, 1);
        $componentId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", ['product_id' => $product->id, 'quantity' => 1, 'condition' => 'GOOD'], $headers)->json('data.id');

        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$componentId}", [], $headers)->assertOk();
        $this->assertSame(0, WorkOrderPartReturn::query()->where('work_order_removed_component_id', $componentId)->count());
    }

    public function test_migration_backfill_classifies_legacy_rows_and_queues_removed_components(): void
    {
        [$tenant, $warehouse, $product, $wo, $part] = $this->setUpIssued();
        $legacyRestocked = WorkOrderPartReturn::query()->create([
            'tenant_id' => $tenant->id, 'work_order_planned_part_id' => $part->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'quantity' => 1, 'condition' => 'UNUSED_NEW', 'disposition_status' => 'RESTOCKED',
        ]);
        $legacyUsed = WorkOrderPartReturn::query()->create([
            'tenant_id' => $tenant->id, 'work_order_planned_part_id' => $part->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'quantity' => 1, 'condition' => 'USED_GOOD', 'disposition_status' => 'PENDING_INSPECTION',
        ]);
        DB::table('work_order_part_returns')->whereIn('id', [$legacyRestocked->id, $legacyUsed->id])->update(['work_order_id' => null, 'return_number' => null, 'return_source' => 'NEW_PART']);
        $pending = WorkOrderRemovedComponent::query()->create(['tenant_id' => $tenant->id, 'work_order_id' => $wo->id, 'product_id' => $product->id, 'quantity' => 1, 'condition' => 'GOOD', 'status' => 'PENDING_RETURN', 'removed_at' => now()]);

        $migration = require database_path('migrations/2026_10_01_000004_add_return_processing_to_work_order_part_returns.php');
        $migration->backfill();
        $migration->backfill();

        $this->assertSame(['NEW_PART', 'RTN-LEGACY-000001', $wo->id], [$legacyRestocked->fresh()->return_source, $legacyRestocked->fresh()->return_number, $legacyRestocked->fresh()->work_order_id]);
        $this->assertSame(['USED_PART', null], [$legacyUsed->fresh()->return_source, $legacyUsed->fresh()->return_number]);
        $queued = WorkOrderPartReturn::query()->where('work_order_removed_component_id', $pending->id)->get();
        $this->assertCount(1, $queued);
        $this->assertSame(['REMOVED_COMPONENT', 'PENDING_RETURN', 'USED_GOOD'], [$queued->first()->return_source, $queued->first()->disposition_status, $queued->first()->condition]);
    }
}
