<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner decision: the Return popup's "Used Qty" is the old/removed
 * component taken off the vehicle when a replacement part is installed —
 * NOT unused issued stock being returned, and NEVER a reversal of the
 * newly-installed replacement's own consumption. See the
 * 2026_09_28_000003 migration's docblock for the full architecture.
 */
class WorkOrderRemovedComponentTest extends TestCase
{
    private function setUpWorkOrder(): array
    {
        $tenant = $this->makeTenant(['code' => 'RC-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $this->grantModule($tenant, 'INVENTORY');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $newProduct = $this->makeProduct($tenant, null, null, ['name' => 'New Brake Pad']);
        $oldProduct = $this->makeProduct($tenant, null, null, ['name' => 'Old Brake Pad']);

        app(InventoryService::class)->receive($warehouse, $newProduct, 100, 15, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return', 'worker.assign',
        ]);
        $headers = $this->authHeaders($token);

        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null);
        $wo = app(WorkOrderService::class)->submit($wo);
        $wo = app(WorkOrderService::class)->approve($wo);
        $wo = app(WorkOrderService::class)->assign($wo);
        $wo = app(WorkOrderService::class)->schedule($wo);
        $wo = app(WorkOrderService::class)->start($wo);

        // New part: issued and consumed — the replacement installation.
        $partId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts", [
            'product_id' => $newProduct->id, 'description' => 'New Brake Pad', 'quantity' => 4,
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$partId}/reserve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$partId}/issue", ['quantity' => 4], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$partId}/consume", ['quantity' => 4], $headers)->assertOk();

        return [$tenant, $warehouse, $newProduct, $oldProduct, $wo, $partId, $headers];
    }

    public function test_removing_and_returning_an_old_component_does_not_reverse_the_new_parts_consumption(): void
    {
        [, $warehouse, $newProduct, $oldProduct, $wo, $newPartId, $headers] = $this->setUpWorkOrder();

        $removedId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'replaced_by_planned_part_id' => $newPartId, 'product_id' => $oldProduct->id,
            'quantity' => 4, 'condition' => 'GOOD', 'notes' => 'Worn but intact',
        ], $headers)->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/return", [
            'warehouse_id' => $warehouse->id,
        ], $headers)->assertOk()->assertJsonPath('data.status', 'RETURNED');

        // The NEW part remains consumed — the removed-component return is not a reversal.
        $newPart = WorkOrderPlannedPart::query()->findOrFail($newPartId);
        $this->assertSame('4.0000', $newPart->consumed_quantity);
        $this->assertSame('CONSUMED', $newPart->status);

        // The removed component's return writes exactly one zero-balance-effect ledger entry —
        // it must never silently become normal available stock (the same zero-effect posture
        // CONSUME/SALE already use: a warehouse_stocks row is the ledger anchor, but
        // quantity_on_hand itself is never incremented).
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $oldProduct->id)->first();
        $this->assertSame(0.0, (float) ($stock->quantity_on_hand ?? 0), 'The old/removed product must never gain available stock from this return.');

        $movement = StockMovement::query()->where('product_id', $oldProduct->id)->where('movement_type', 'REMOVED_COMPONENT_RETURN')->first();
        $this->assertNotNull($movement);
        $this->assertSame('4.0000', $movement->quantity);

        // The NEW product's own stock (from the earlier issue) is unaffected by the old
        // component's return — they are entirely separate products/movements.
        $newStock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $newProduct->id)->first();
        $this->assertSame(96.0, (float) $newStock->quantity_on_hand); // 100 - 4 issued, never restored
    }

    public function test_removed_component_return_is_rejected_once_already_returned(): void
    {
        [, $warehouse, , $oldProduct, $wo, $newPartId, $headers] = $this->setUpWorkOrder();

        $removedId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'replaced_by_planned_part_id' => $newPartId, 'product_id' => $oldProduct->id,
            'quantity' => 4, 'condition' => 'GOOD',
        ], $headers)->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/return", ['warehouse_id' => $warehouse->id], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/return", ['warehouse_id' => $warehouse->id], $headers)->assertStatus(422);
    }

    public function test_removed_component_old_product_can_differ_from_the_new_installed_product(): void
    {
        [, , $newProduct, $oldProduct, $wo, $newPartId, $headers] = $this->setUpWorkOrder();
        $this->assertNotSame($newProduct->id, $oldProduct->id);

        $response = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'replaced_by_planned_part_id' => $newPartId, 'product_id' => $oldProduct->id,
            'quantity' => 1, 'condition' => 'FAULTY',
        ], $headers)->assertStatus(201);

        $this->assertSame($oldProduct->id, $response->json('data.product_id'));
        $this->assertNotSame($newProduct->id, $response->json('data.product_id'));
    }

    public function test_removed_component_without_a_replacement_part_is_allowed(): void
    {
        [, , , $oldProduct, $wo, , $headers] = $this->setUpWorkOrder();

        // A straight removal/decommission with no like-for-like replacement.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'FAULTY',
        ], $headers)->assertStatus(201)->assertJsonPath('data.replaced_by_planned_part_id', null);
    }

    public function test_removed_component_requires_a_valid_condition(): void
    {
        [, , , $oldProduct, $wo, , $headers] = $this->setUpWorkOrder();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'PRISTINE',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['condition']);
    }

    public function test_pending_removed_component_can_be_deleted_but_a_returned_one_cannot(): void
    {
        [, $warehouse, , $oldProduct, $wo, , $headers] = $this->setUpWorkOrder();

        $removedId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'GOOD',
        ], $headers)->assertStatus(201)->json('data.id');

        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}", [], $headers)->assertOk();
        $this->assertSoftDeletedOrMissing($removedId);

        $removedId2 = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'GOOD',
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId2}/return", ['warehouse_id' => $warehouse->id], $headers)->assertOk();

        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId2}", [], $headers)->assertStatus(422);
    }

    private function assertSoftDeletedOrMissing(string $id): void
    {
        $this->assertDatabaseMissing('work_order_removed_components', ['id' => $id]);
    }

    public function test_removal_is_rejected_while_work_order_is_not_executable(): void
    {
        [$tenant, , , $oldProduct, , , $headers] = $this->setUpWorkOrder();
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $draftWo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null);

        $this->postJson("/api/v1/app/work-orders/{$draftWo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'GOOD',
        ], $headers)->assertStatus(422);
    }

    public function test_removed_component_evidence_upload_accepts_jpg_and_png_and_rejects_others(): void
    {
        [, , , $oldProduct, $wo, , $headers] = $this->setUpWorkOrder();
        $removedId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'FAULTY',
        ], $headers)->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/evidence", [
            'file' => UploadedFile::fake()->image('front.jpg', 200, 200),
        ], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/evidence", [
            'file' => UploadedFile::fake()->image('side.png', 200, 200),
        ], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/evidence", [
            'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ], $headers)->assertStatus(422);

        $list = $this->getJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/evidence", $headers)->assertOk();
        $this->assertCount(2, $list->json('data'));
    }

    public function test_removed_component_evidence_cannot_be_removed_once_the_component_is_returned(): void
    {
        [, $warehouse, , $oldProduct, $wo, , $headers] = $this->setUpWorkOrder();
        $removedId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'GOOD',
        ], $headers)->assertStatus(201)->json('data.id');
        $evidenceId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/evidence", [
            'file' => UploadedFile::fake()->image('evidence.jpg', 200, 200),
        ], $headers)->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/return", ['warehouse_id' => $warehouse->id], $headers)->assertOk();

        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/removed-components/{$removedId}/evidence/{$evidenceId}", [], $headers)->assertStatus(422);
    }

    public function test_removed_component_is_tenant_scoped(): void
    {
        [$tenantA, , , $oldProductA, $woA, , $headersA] = $this->setUpWorkOrder();
        $removedId = $this->postJson("/api/v1/app/work-orders/{$woA->id}/removed-components", [
            'product_id' => $oldProductA->id, 'quantity' => 1, 'condition' => 'GOOD',
        ], $headersA)->assertStatus(201)->json('data.id');

        // A minimal second tenant/user is enough here — no need for the full setUpWorkOrder()
        // fixture (issue+consume, several direct-service transitions) just to prove isolation.
        $tenantB = $this->makeTenant(['code' => 'RC-B-'.Str::random(4)]);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['maintenance_job.manage']);
        $this->assertNotSame($tenantA->id, $tenantB->id);

        $this->deleteJson("/api/v1/app/work-orders/{$woA->id}/removed-components/{$removedId}", [], $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_removed_component_actions_require_permission(): void
    {
        [$tenant, , , $oldProduct, $wo] = $this->setUpWorkOrder();
        [, $noPermToken] = $this->makeTenantUser($tenant, ['work_order.view']);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $oldProduct->id, 'quantity' => 1, 'condition' => 'GOOD',
        ], $this->authHeaders($noPermToken))->assertStatus(403);
    }
}
