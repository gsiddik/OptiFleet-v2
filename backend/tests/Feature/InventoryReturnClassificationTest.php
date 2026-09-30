<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G-14: return-condition classification. G-18: returnPart() concurrency fix.
 */
class InventoryReturnClassificationTest extends TestCase
{
    private function setUpWorkOrderWithIssuedPart(float $plannedQty = 10, float $issueQty = 10): array
    {
        $tenant = $this->makeTenant(['code' => 'RET-'.Str::random(4)]);
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

        app(InventoryService::class)->receive($warehouse, $product, 100, 15, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return',
        ]);
        $headers = $this->authHeaders($token);

        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null);
        $wo = app(WorkOrderService::class)->submit($wo);
        $wo = app(WorkOrderService::class)->approve($wo);
        $wo = app(WorkOrderService::class)->assign($wo);
        $wo = app(WorkOrderService::class)->schedule($wo);
        $wo = app(WorkOrderService::class)->start($wo);

        // Part Requests issue the whole approved quantity; $plannedQty is kept for the callers' signature.
        $part = $this->issueThroughPartRequest($wo, $product, $issueQty, $warehouse);

        return [$tenant, $warehouse, $product, $wo, $part->fresh(), $headers];
    }

    public function test_unused_new_return_restocks_available_inventory(): void
    {
        [, $warehouse, $product, $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart();

        $response = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 4, 'condition' => 'UNUSED_NEW', 'reason' => 'Not needed',
        ], $headers)->assertOk();

        $this->assertSame('4.0000', $response->json('data.returned_quantity'));

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(94.0, (float) $stock->quantity_on_hand); // 100 - 10 issued + 4 restocked

        $return = WorkOrderPartReturn::query()->where('work_order_planned_part_id', $part->id)->firstOrFail();
        $this->assertSame('UNUSED_NEW', $return->condition);
        $this->assertSame('RESTOCKED', $return->disposition_status);
        $this->assertNotNull($return->stock_movement_id);
    }

    public function test_return_evidence_is_optional_and_stored_when_provided(): void
    {
        [, , , $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 2, 'condition' => 'USED_FAULTY', 'evidence' => 'https://files.example/evidence/photo-1.jpg',
        ], $headers)->assertOk();

        $return = WorkOrderPartReturn::query()->where('work_order_planned_part_id', $part->id)->firstOrFail();
        $this->assertSame('https://files.example/evidence/photo-1.jpg', $return->evidence);
    }

    public function test_used_good_return_does_not_reach_available_stock(): void
    {
        [, $warehouse, $product, $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 3, 'condition' => 'USED_GOOD',
        ], $headers)->assertOk();

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(90.0, (float) $stock->quantity_on_hand); // unchanged by the return — only the ISSUE deducted it

        $return = WorkOrderPartReturn::query()->where('work_order_planned_part_id', $part->id)->firstOrFail();
        $this->assertSame('USED_GOOD', $return->condition);
        $this->assertSame('PENDING_INSPECTION', $return->disposition_status);
        $this->assertNull($return->stock_movement_id);
    }

    public function test_used_faulty_return_does_not_reach_available_stock(): void
    {
        [, $warehouse, $product, $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 2, 'condition' => 'USED_FAULTY',
        ], $headers)->assertOk();

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(90.0, (float) $stock->quantity_on_hand);

        $return = WorkOrderPartReturn::query()->where('work_order_planned_part_id', $part->id)->firstOrFail();
        $this->assertSame('USED_FAULTY', $return->condition);
        $this->assertSame('PENDING_INSPECTION', $return->disposition_status);
    }

    public function test_return_without_condition_is_rejected(): void
    {
        [, , , $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 1,
        ], $headers)->assertStatus(422);
    }

    public function test_partial_return_leaves_remaining_outstanding(): void
    {
        [, , , $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart(10, 10);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 3, 'condition' => 'UNUSED_NEW',
        ], $headers)->assertOk();

        $part->refresh();
        $this->assertSame(3.0, (float) $part->returned_quantity);
        $this->assertSame(7.0, $part->outstandingIssued());
        // recomputeStatus() is unchanged by this work: issued(10) >= planned(10) still reads as ISSUED
        // until returned/consumed reaches the full issued quantity — only outstandingIssued() (asserted
        // above) is the ceiling this feature actually needs to get right.
        $this->assertSame('ISSUED', $part->status);
    }

    public function test_over_return_is_rejected(): void
    {
        [, , , $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart(5, 5);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 6, 'condition' => 'UNUSED_NEW',
        ], $headers)->assertStatus(422);

        $part->refresh();
        $this->assertSame(0.0, (float) $part->returned_quantity);
    }

    public function test_duplicate_return_cannot_double_credit_stock(): void
    {
        [, $warehouse, $product, $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart(5, 5);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 5, 'condition' => 'UNUSED_NEW',
        ], $headers)->assertOk();

        // Attempting to return the same fully-returned part again is rejected, not double-credited.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 5, 'condition' => 'UNUSED_NEW',
        ], $headers)->assertStatus(422);

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(100.0, (float) $stock->quantity_on_hand); // 100 - 5 issued + 5 returned once, never twice
    }

    /**
     * G-18: a true concurrent double-return (two overlapping requests racing
     * on separate connections) cannot be exercised inside a single
     * RefreshDatabase-wrapped test — the harness wraps every test in one
     * outer transaction, so a second connection can never see the first
     * connection's uncommitted row under READ COMMITTED, making any
     * cross-connection NOWAIT-lock probe meaningless here (it would "pass"
     * for the wrong reason: nothing to lock, not "successfully blocked").
     * This is the same constraint the source report's own concurrency
     * smoke test flags as requiring real forked OS processes — NOT RUN in
     * this environment for that reason. What the sequential test below
     * proves instead: the SAME return path used by both would-be racers
     * rejects the second attempt once the first has posted, because both
     * reads happen against the row `returnPart()` explicitly re-locks and
     * re-fetches inside its own transaction (fixed in this change; see
     * WorkOrderPartService::returnPart()) rather than the caller's stale
     * copy — so the two calls can never disagree about outstandingIssued().
     */
    public function test_sequential_returns_never_disagree_about_outstanding_quantity(): void
    {
        [, $warehouse, $product, $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart(5, 5);

        // Two callers each hold the *same* stale, pre-return snapshot of the part (outstanding = 5),
        // exactly as two concurrent requests would after both reading before either writes.
        $staleSnapshotA = WorkOrderPlannedPart::query()->findOrFail($part->id);
        $staleSnapshotB = WorkOrderPlannedPart::query()->findOrFail($part->id);
        $this->assertSame(5.0, $staleSnapshotA->outstandingIssued());
        $this->assertSame(5.0, $staleSnapshotB->outstandingIssued());

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 5, 'condition' => 'UNUSED_NEW',
        ], $headers)->assertOk();

        // The second caller's identically-stale snapshot must not be trusted by the server —
        // returnPart() re-locks and re-fetches the row itself, so this is rejected, not double-credited.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 5, 'condition' => 'UNUSED_NEW',
        ], $headers)->assertStatus(422);

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(100.0, (float) $stock->quantity_on_hand);
    }

    public function test_consume_writes_an_immutable_consume_ledger_entry(): void
    {
        [, $warehouse, $product, $wo, $part, $headers] = $this->setUpWorkOrderWithIssuedPart(5, 5);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/consume", [
            'quantity' => 5,
        ], $headers)->assertOk();

        $movement = StockMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('movement_type', 'CONSUME')
            ->where('reference_type', WorkOrderPlannedPart::class)
            ->where('reference_id', $part->id)
            ->first();

        $this->assertNotNull($movement, 'consume() must write an immutable CONSUME stock_movements row.');
        $this->assertSame(5.0, (float) $movement->quantity);

        // Stock on-hand is unaffected by CONSUME — it was already decremented at ISSUE time.
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(95.0, (float) $stock->quantity_on_hand);
    }
}
