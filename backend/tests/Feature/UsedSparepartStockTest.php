<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Warehouse Stock → Used Spareparts: REUSE and Repair → Reuse are reusable (posted once into the
 * product's on-hand), QUARANTINE and repair-pending are visible but never available, and one
 * physical removed part is always one representation.
 */
class UsedSparepartStockTest extends TestCase
{
    private const MAKER = ['maintenance_job.manage', 'inventory.view', 'used_part.view', 'used_part.inspect', 'used_part.dispose'];

    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'USS-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop, ['name' => 'Main Warehouse']);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Alternator', 'sku' => 'SP-ALT-'.Str::random(4)]);
        app(InventoryService::class)->receive($warehouse, $product, 20, 100, 'OPENING', null, null, null);
        $service = app(WorkOrderService::class);
        $wo = $service->start($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null)
        )))));
        [, $makerToken] = $this->makeTenantUser($tenant, self::MAKER);
        [, $approverToken] = $this->makeTenantUser($tenant, ['used_part.view', 'used_part.approve']);

        return [$tenant, $warehouse, $product, $wo, $vehicle, $this->authHeaders($makerToken), $this->authHeaders($approverToken)];
    }

    /** Remove → receive → inspect → propose → approve. */
    private function usedPart(array $ctx, float $qty, string $condition, string $disposition): WorkOrderPartReturn
    {
        [, $warehouse, $product, $wo, , $maker, $approver] = $ctx;
        $this->consumeOnWorkOrder($wo, $product, $qty, $warehouse);
        $componentId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", ['product_id' => $product->id, 'quantity' => $qty, 'condition' => $condition === 'USED_GOOD' ? 'GOOD' : 'FAULTY'], $maker)->assertCreated()->json('data.id');
        $return = WorkOrderPartReturn::query()->where('work_order_removed_component_id', $componentId)->firstOrFail();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/receive", ['warehouse_id' => $warehouse->id], $maker)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", ['accepted_quantity' => $qty, 'condition' => $condition], $maker)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", ['disposition' => $disposition], $maker)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/decide", ['decision' => 'APPROVE'], $approver)->assertOk()->assertJsonPath('data.disposition_status', 'FINALIZED');

        return $return->fresh();
    }

    private function onHand($warehouse, $product): float
    {
        return (float) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_on_hand');
    }

    private function listing(array $headers, string $query = ''): array
    {
        return $this->getJson('/api/v1/app/inventory/used-spareparts?'.$query, $headers)->assertOk()->json();
    }

    public function test_reuse_is_reusable_and_counted_once_in_on_hand(): void
    {
        $ctx = $this->setUpTenant();
        [, $warehouse, $product, $wo, $vehicle, $maker] = $ctx;
        $before = $this->onHand($warehouse, $product);
        $reuse = $this->usedPart($ctx, 3, 'USED_GOOD', 'REUSE');

        $this->assertSame($before - 3 + 3, $this->onHand($warehouse, $product), 'Issued 3 for the new part, the 3 used ones return once.');
        $this->assertSame(1, StockMovement::query()->where('reference_type', WorkOrderPartReturn::class)->where('reference_id', $reuse->id)->count(), 'Exactly one stock posting.');

        $payload = $this->listing($maker);
        $this->assertCount(1, $payload['data'], 'One physical part → one representation.');
        $row = $payload['data'][0];
        $this->assertSame(['REUSABLE', 'REUSE', '3', false, false], [$row['category'], $row['disposition'], $row['quantity'], $row['repaired'], $row['available_for_issue']]);
        $this->assertSame([$product->name, $wo->wo_number, $vehicle->registration_number, 'Main Warehouse'], [$row['product']['name'], $row['work_order']['wo_number'], $row['vehicle']['registration_number'], $row['warehouse']['name']]);
        $this->assertEquals(['reusable_qty' => 3, 'quarantine_qty' => 0, 'repair_pending_qty' => 0], $payload['meta']['summary']);
    }

    public function test_repair_is_pending_until_completed_then_reuse_after_approval(): void
    {
        $ctx = $this->setUpTenant();
        [, $warehouse, $product, , , $maker, $approver] = $ctx;
        $repair = $this->usedPart($ctx, 2, 'USED_FAULTY', 'REPAIR');
        $onHandAfterRepair = $this->onHand($warehouse, $product);

        $row = $this->listing($maker)['data'][0];
        $this->assertSame('REPAIR_PENDING', $row['category']);
        $this->assertEquals(0, $this->listing($maker, 'category=REUSABLE')['meta']['summary']['reusable_qty'], 'Repair pending is never reusable.');
        $this->assertCount(0, $this->listing($maker, 'category=REUSABLE')['data']);

        $this->postJson("/api/v1/app/used-part-returns/{$repair->id}/complete-repair", ['notes' => 'Bearings replaced'], $maker)
            ->assertOk()->assertJsonPath('data.disposition_status', 'INSPECTED')->assertJsonPath('data.condition', 'USED_GOOD');
        $this->postJson("/api/v1/app/used-part-returns/{$repair->id}/complete-repair", [], $maker)->assertStatus(422);
        $this->assertCount(0, $this->listing($maker)['data'], 'Repaired but not yet re-dispositioned: not reusable.');
        $this->assertSame($onHandAfterRepair, $this->onHand($warehouse, $product), 'Completing a repair never moves stock.');

        $this->postJson("/api/v1/app/used-part-returns/{$repair->id}/propose-disposition", ['disposition' => 'REUSE'], $maker)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$repair->id}/decide", ['decision' => 'APPROVE'], $approver)->assertOk();

        $row = $this->listing($maker, 'category=REUSABLE')['data'][0];
        $this->assertSame(['REUSABLE', true], [$row['category'], $row['repaired']]);
        $this->assertSame($onHandAfterRepair + 2, $this->onHand($warehouse, $product));
    }

    public function test_quarantine_is_visible_but_never_available(): void
    {
        $ctx = $this->setUpTenant();
        [, $warehouse, $product, , , $maker] = $ctx;
        $this->usedPart($ctx, 3, 'USED_GOOD', 'REUSE');
        $onHand = $this->onHand($warehouse, $product);
        $this->usedPart($ctx, 2, 'USED_FAULTY', 'QUARANTINE');

        $payload = $this->listing($maker);
        $this->assertEquals(['reusable_qty' => 3, 'quarantine_qty' => 2, 'repair_pending_qty' => 0], $payload['meta']['summary'], 'Reusable and quarantine are never added together.');
        $quarantine = collect($payload['data'])->firstWhere('category', 'QUARANTINE');
        $this->assertSame(['2', 'QUARANTINE'], [$quarantine['quantity'], $quarantine['disposition']]);
        $this->assertSame($onHand - 2, $this->onHand($warehouse, $product), 'Only the new part issue moved stock; quarantine added nothing.');
        $stock = $this->getJson("/api/v1/app/inventory?item_group=PARTS_SUPPLIES&search={$product->sku}", $maker)->assertOk()->json('data.0');
        $this->assertSame((float) $stock['quantity_on_hand'], (float) $stock['quantity_available'], 'Quarantined units never appear in available stock.');
    }

    public function test_scrapped_and_unfinished_items_are_not_listed_and_filters_work(): void
    {
        $ctx = $this->setUpTenant();
        [$tenant, $warehouse, $product, $wo, $vehicle, $maker] = $ctx;
        $this->usedPart($ctx, 1, 'USED_FAULTY', 'SCRAP');
        $this->usedPart($ctx, 1, 'USED_GOOD', 'REUSE');
        $this->assertCount(1, $this->listing($maker)['data'], 'Scrapped parts are not stock.');

        $this->assertCount(1, $this->listing($maker, "warehouse_id={$warehouse->id}&product_id={$product->id}&work_order_id={$wo->id}&vehicle_id={$vehicle->id}")['data']);
        $this->assertCount(1, $this->listing($maker, 'search='.urlencode($wo->wo_number))['data']);
        $this->assertCount(0, $this->listing($maker, 'category=QUARANTINE')['data']);
        $this->getJson('/api/v1/app/inventory/used-spareparts?category=SCRAP', $maker)->assertStatus(422);

        $otherWarehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        [, $scoped] = $this->makeTenantUser($tenant, ['inventory.view'], ['WAREHOUSE' => $otherWarehouse->id]);
        $this->assertCount(0, $this->listing($this->authHeaders($scoped))['data'], 'Warehouse data scope applies.');
        [, $noPerm] = $this->makeTenantUser($tenant, ['used_part.view']);
        $this->getJson('/api/v1/app/inventory/used-spareparts', $this->authHeaders($noPerm))->assertForbidden();

        $other = $this->makeTenant(['code' => 'USX-'.Str::random(4)]);
        $this->grantModule($other, 'INVENTORY');
        [, $foreign] = $this->makeTenantUser($other, ['inventory.view']);
        $this->assertCount(0, $this->listing($this->authHeaders($foreign))['data']);
    }
}
