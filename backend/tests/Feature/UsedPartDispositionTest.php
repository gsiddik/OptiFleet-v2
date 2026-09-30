<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G-15: Used Sparepart Processing — inspect -> propose disposition -> decide.
 */
class UsedPartDispositionTest extends TestCase
{
    private function permissions(): array
    {
        return [
            'maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return',
            'used_part.view', 'used_part.inspect', 'used_part.dispose', 'used_part.approve',
        ];
    }

    /** @return array{0: Tenant, 1: Warehouse, 2: Product, 3: WorkOrderPartReturn, 4: array} */
    private function setUpUsedReturn(string $condition = 'USED_GOOD', float $qty = 5): array
    {
        $tenant = $this->makeTenant(['code' => 'UPD-'.Str::random(4)]);
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
        $product = $this->makeProduct($tenant);
        app(InventoryService::class)->receive($warehouse, $product, 50, 15, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null);
        $wo = app(WorkOrderService::class)->submit($wo);
        $wo = app(WorkOrderService::class)->approve($wo);
        $wo = app(WorkOrderService::class)->assign($wo);
        $wo = app(WorkOrderService::class)->schedule($wo);
        $wo = app(WorkOrderService::class)->start($wo);

        // An old component taken off the vehicle (Removed Components) is the Used Sparepart
        // Processing source; receiving it into a warehouse queues it for inspection.
        $componentId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $product->id, 'quantity' => $qty, 'condition' => $condition === 'USED_GOOD' ? 'GOOD' : 'FAULTY',
        ], $headers)->assertStatus(201)->json('data.id');
        $return = WorkOrderPartReturn::query()->where('work_order_removed_component_id', $componentId)->firstOrFail();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/receive", ['warehouse_id' => $warehouse->id], $headers)
            ->assertOk()->assertJsonPath('data.disposition_status', 'PENDING_INSPECTION');
        $return->refresh();

        return [$tenant, $warehouse, $product, $return, $headers];
    }

    public function test_disposition_cannot_be_proposed_before_inspection(): void
    {
        [, , , $return, $headers] = $this->setUpUsedReturn();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'REUSE',
        ], $headers)->assertStatus(422);

        $this->assertSame('PENDING_INSPECTION', $return->fresh()->disposition_status);
    }

    /** Final reconciliation (queued ADJUST): inspector's own evidence photo, distinct from the returner's `evidence`. */
    public function test_inspection_evidence_is_optional_and_stored_separately_from_return_evidence(): void
    {
        [, , , $return, $headers] = $this->setUpUsedReturn();

        $response = $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 5, 'condition' => 'USED_GOOD',
            'evidence' => 'https://files.example/inspection-photo.jpg',
        ], $headers)->assertOk();

        $this->assertSame('https://files.example/inspection-photo.jpg', $response->json('data.inspection_evidence'));
        $this->assertNull($response->json('data.evidence'));
    }

    public function test_decide_before_proposal_is_rejected(): void
    {
        [, , , $return, $headers] = $this->setUpUsedReturn();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 5, 'condition' => 'USED_GOOD',
        ], $headers)->assertOk();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/decide", [
            'decision' => 'APPROVE',
        ], $headers)->assertStatus(422);
    }

    public function test_faulty_item_cannot_be_proposed_for_reuse_or_sale(): void
    {
        [, , , $return, $headers] = $this->setUpUsedReturn('USED_FAULTY');

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 5, 'condition' => 'USED_FAULTY',
        ], $headers)->assertOk();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'REUSE',
        ], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'SELL_ELIGIBLE',
        ], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'SCRAP', 'reason' => 'Cracked housing',
        ], $headers)->assertOk()->assertJsonPath('data.disposition_status', 'PENDING_APPROVAL');
    }

    public function test_maker_cannot_approve_own_disposition(): void
    {
        [$tenant, , , $return, $headers] = $this->setUpUsedReturn();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 5, 'condition' => 'USED_GOOD',
        ], $headers)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'REUSE',
        ], $headers)->assertOk();

        // Same actor (same token/user) who proposed now tries to decide.
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/decide", [
            'decision' => 'APPROVE',
        ], $headers)->assertStatus(422);

        $this->assertSame('PENDING_APPROVAL', $return->fresh()->disposition_status);
    }

    public function test_approved_reuse_disposition_restocks_inventory_only_after_approval(): void
    {
        [$tenant, $warehouse, $product, $return, $makerHeaders] = $this->setUpUsedReturn('USED_GOOD', 5);
        [, $approverToken] = $this->makeTenantUser($tenant, ['used_part.approve']);
        $approverHeaders = $this->authHeaders($approverToken);

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 5, 'condition' => 'USED_GOOD',
        ], $makerHeaders)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'REUSE',
        ], $makerHeaders)->assertOk();

        // Not yet restocked — approval is still pending.
        $stockBefore = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(50.0, (float) $stockBefore->quantity_on_hand); // receiving the old component never adds available stock

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/decide", [
            'decision' => 'APPROVE',
        ], $approverHeaders)->assertOk()->assertJsonPath('data.disposition_status', 'FINALIZED');

        $stockAfter = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(55.0, (float) $stockAfter->quantity_on_hand); // the accepted 5 become available only now that reuse is approved
    }

    public function test_approved_scrap_disposition_never_touches_inventory(): void
    {
        [$tenant, $warehouse, $product, $return, $makerHeaders] = $this->setUpUsedReturn('USED_FAULTY', 3);
        [, $approverToken] = $this->makeTenantUser($tenant, ['used_part.approve']);
        $approverHeaders = $this->authHeaders($approverToken);

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 3, 'condition' => 'USED_FAULTY',
        ], $makerHeaders)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'SCRAP', 'reason' => 'Beyond repair',
        ], $makerHeaders)->assertOk();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/decide", [
            'decision' => 'APPROVE',
        ], $approverHeaders)->assertOk()->assertJsonPath('data.disposition_status', 'FINALIZED');

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(50.0, (float) $stock->quantity_on_hand); // unchanged by receiving and by the scrap disposition
    }

    public function test_rejected_disposition_can_be_reproposed(): void
    {
        [$tenant, , , $return, $makerHeaders] = $this->setUpUsedReturn('USED_GOOD', 4);
        [, $approverToken] = $this->makeTenantUser($tenant, ['used_part.approve']);
        $approverHeaders = $this->authHeaders($approverToken);

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 4, 'condition' => 'USED_GOOD',
        ], $makerHeaders)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'REUSE',
        ], $makerHeaders)->assertOk();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/decide", [
            'decision' => 'REJECT', 'note' => 'Needs a second look',
        ], $approverHeaders)->assertOk()->assertJsonPath('data.disposition_status', 'REJECTED');

        // Re-propose a different disposition without needing to re-inspect.
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'QUARANTINE',
        ], $makerHeaders)->assertOk()->assertJsonPath('data.disposition_status', 'PENDING_APPROVAL');
    }

    public function test_disposition_actions_require_permission(): void
    {
        [$tenant, , , $return] = $this->setUpUsedReturn();
        [, $unprivilegedToken] = $this->makeTenantUser($tenant, ['maintenance_job.manage']);
        $headers = $this->authHeaders($unprivilegedToken);

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => 5, 'condition' => 'USED_GOOD',
        ], $headers)->assertStatus(403);
    }

    public function test_used_part_return_is_tenant_scoped(): void
    {
        [, , , $return] = $this->setUpUsedReturn();
        $otherTenant = $this->makeTenant(['code' => 'UPDB-'.Str::random(4)]);
        [, $otherToken] = $this->makeTenantUser($otherTenant, ['used_part.view']);

        $this->getJson("/api/v1/app/used-part-returns/{$return->id}", $this->authHeaders($otherToken))->assertStatus(404);
    }
}
