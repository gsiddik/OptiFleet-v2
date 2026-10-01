<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Part Requests are the only issuing path for Work Order parts:
 * Work Order "Reserve" -> REQUESTED -> APPROVED | REJECTED | CANCELLED; APPROVED -> ISSUED.
 * Issuing posts the stock movement through the existing inventory engine, exactly once.
 */
class WorkOrderPartRequestTest extends TestCase
{
    private const ALL = ['part_request.view', 'part_request.create', 'part_request.approve', 'part_request.reject', 'part_request.cancel', 'part_request.issue'];

    private function setUpWorkOrder(float $stock = 20): array
    {
        $tenant = $this->makeTenant(['code' => 'WPR-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        if ($stock > 0) {
            app(InventoryService::class)->receive($warehouse, $product, $stock, 15, 'OPENING', null, null, null);
        }

        [$user] = $this->makeTenantUser($tenant, []);
        $service = app(WorkOrderService::class);
        $wo = $service->start($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id)
        )))));

        return [$tenant, $warehouse, $product, $wo];
    }

    private function reserve($wo, $product, float $qty, array $headers): string
    {
        return $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $product->id, 'quantity_requested' => $qty]]], $headers)
            ->assertStatus(201)->json('data.id');
    }

    private function onHand($warehouse, $product): float
    {
        return (float) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_on_hand');
    }

    public function test_reserve_creates_a_requested_part_request_identified_by_the_product(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [$mechanic, $token] = $this->makeTenantUser($tenant, ['part_request.create']);

        $response = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'notes' => 'Front brake job',
            // A client-sent description is ignored: the Product master is the item's identity.
            'items' => [['product_id' => $product->id, 'quantity_requested' => 4, 'description' => 'something else']],
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('REQUESTED', $response->json('data.status'));
        $this->assertSame($wo->id, $response->json('data.work_order_id'));
        $this->assertSame($mechanic->id, $response->json('data.requested_by'));
        $this->assertNotNull($response->json('data.requested_at'));
        $this->assertSame($product->id, $response->json('data.items.0.product_id'));
        $this->assertSame('Brake Pad Set', $response->json('data.items.0.description'));
        $this->assertSame(0, StockMovement::query()->where('product_id', $product->id)->where('movement_type', 'ISSUE')->count(), 'Reserve never moves stock.');
    }

    public function test_product_is_mandatory_and_must_be_the_tenants_active_product(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create']);
        $headers = $this->authHeaders($token);
        $other = $this->makeProduct($this->makeTenant());
        $inactive = $this->makeProduct($tenant, null, null, ['status' => 'INACTIVE']);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['description' => 'Generic gasket', 'quantity_requested' => 1]]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $other->id, 'quantity_requested' => 1]]], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $inactive->id, 'quantity_requested' => 1]]], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => []], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $product->id, 'quantity_requested' => 0]]], $headers)->assertStatus(422);
        $this->assertSame(0, WorkOrderPartRequest::query()->where('work_order_id', $wo->id)->count());
    }

    public function test_request_is_rejected_while_work_order_is_not_executable(): void
    {
        [$tenant, , $product] = $this->setUpWorkOrder();
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        [$user, $token] = $this->makeTenantUser($tenant, ['part_request.create']);
        $draft = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id);

        $this->postJson("/api/v1/app/work-orders/{$draft->id}/part-requests", ['items' => [['product_id' => $product->id, 'quantity_requested' => 1]]], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_approve_then_issue_reduces_inventory_exactly_once(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder(20);
        [$storeman, $token] = $this->makeTenantUser($tenant, self::ALL);
        $headers = $this->authHeaders($token);
        $id = $this->reserve($wo, $product, 4, $headers);

        $approved = $this->postJson("/api/v1/app/part-requests/{$id}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $part = WorkOrderPlannedPart::query()->findOrFail($approved->json('data.items.0.planned_part_id'));
        $this->assertSame([4.0, 'PLANNED'], [(float) $part->planned_quantity, $part->status]);
        $this->assertSame(20.0, $this->onHand($warehouse, $product), 'Approval never moves stock.');

        $issued = $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $warehouse->id], $headers)->assertOk();
        $this->assertSame('ISSUED', $issued->json('data.status'));
        $this->assertSame($storeman->id, $issued->json('data.issued_by'));
        $this->assertNotNull($issued->json('data.issued_at'));
        $this->assertSame($warehouse->id, $issued->json('data.warehouse_id'));
        $this->assertSame(16.0, $this->onHand($warehouse, $product));
        $this->assertSame(['ISSUED', 4.0], [$part->fresh()->status, (float) $part->fresh()->issued_quantity]);

        // Double click / retry: rejected, stock deducted only once.
        $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'This part request has already been issued.');
        $this->assertSame(16.0, $this->onHand($warehouse, $product));
        $movements = StockMovement::query()->where('product_id', $product->id)->where('movement_type', 'ISSUE')->get();
        $this->assertCount(1, $movements);
        $this->assertSame(WorkOrderPlannedPart::class, $movements->first()->reference_type);
        $this->assertSame($part->id, $movements->first()->reference_id);
    }

    public function test_requested_rejected_and_cancelled_requests_cannot_be_issued(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        $headers = $this->authHeaders($this->makeTenantUser($tenant, self::ALL)[1]);

        $requested = $this->reserve($wo, $product, 1, $headers);
        $this->postJson("/api/v1/app/part-requests/{$requested}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'This part request must be approved before it can be issued.');

        $rejected = $this->reserve($wo, $product, 1, $headers);
        $this->postJson("/api/v1/app/part-requests/{$rejected}/reject", [], $headers)->assertStatus(422); // reason required
        $this->postJson("/api/v1/app/part-requests/{$rejected}/reject", ['reason' => 'Wrong part'], $headers)->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->postJson("/api/v1/app/part-requests/{$rejected}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'A REJECTED part request cannot be issued.');

        $cancelled = $this->reserve($wo, $product, 1, $headers);
        $this->postJson("/api/v1/app/part-requests/{$cancelled}/cancel", [], $headers)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson("/api/v1/app/part-requests/{$cancelled}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'A CANCELLED part request cannot be issued.');

        // Decided requests cannot be decided again.
        $this->postJson("/api/v1/app/part-requests/{$rejected}/approve", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/part-requests/{$cancelled}/cancel", [], $headers)->assertStatus(422);
        $approved = $this->reserve($wo, $product, 1, $headers);
        $this->postJson("/api/v1/app/part-requests/{$approved}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/part-requests/{$approved}/approve", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/part-requests/{$approved}/cancel", [], $headers)->assertStatus(422);

        $this->assertSame(0, StockMovement::query()->where('product_id', $product->id)->where('movement_type', 'ISSUE')->count());
        $this->assertSame(0, WorkOrderPlannedPart::query()->where('work_order_id', $wo->id)->where('issued_quantity', '>', 0)->count());
    }

    public function test_approve_can_partially_approve_and_needs_one_approved_line(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        $headers = $this->authHeaders($this->makeTenantUser($tenant, self::ALL)[1]);

        $id = $this->reserve($wo, $product, 10, $headers);
        $itemId = WorkOrderPartRequest::query()->findOrFail($id)->items()->value('id');
        $this->postJson("/api/v1/app/part-requests/{$id}/approve", ['approved_quantities' => [$itemId => 0]], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/part-requests/{$id}/approve", ['approved_quantities' => [$itemId => 11]], $headers)->assertStatus(422);
        $approve = $this->postJson("/api/v1/app/part-requests/{$id}/approve", ['approved_quantities' => [$itemId => 3]], $headers)->assertOk();
        $this->assertSame(3.0, (float) WorkOrderPlannedPart::query()->findOrFail($approve->json('data.items.0.planned_part_id'))->planned_quantity);
    }

    public function test_insufficient_stock_rolls_the_whole_issue_back(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder(5);
        $second = $this->makeProduct($tenant, null, null, ['name' => 'Oil Filter']);
        app(InventoryService::class)->receive($warehouse, $second, 1, 10, 'OPENING', null, null, null);
        $headers = $this->authHeaders($this->makeTenantUser($tenant, self::ALL)[1]);

        $id = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [
            ['product_id' => $product->id, 'quantity_requested' => 2],
            ['product_id' => $second->id, 'quantity_requested' => 3],
        ]], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/part-requests/{$id}/approve", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertStatus(422)->assertJsonFragment(['message' => 'Line "Oil Filter": Insufficient stock: requested 3, available 1.']);
        $this->assertSame(5.0, $this->onHand($warehouse, $product), 'The first line is rolled back too.');
        $this->assertSame('APPROVED', WorkOrderPartRequest::query()->findOrFail($id)->status);
    }

    public function test_issue_never_takes_stock_reserved_for_another_work_order(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder(5);
        // A legacy reservation holds 4 of the 5 for another line.
        $legacyLine = WorkOrderPlannedPart::query()->create([
            'tenant_id' => $tenant->id, 'work_order_id' => $wo->id, 'product_id' => $product->id, 'description' => 'Legacy',
            'quantity' => 4, 'planned_quantity' => 4, 'status' => 'PLANNED',
        ]);
        // Historical stock held by the retired Inventory Reservation feature.
        app(InventoryService::class)->reserve($warehouse, $product, 4, null, null, null);
        $legacyLine->update(['warehouse_id' => $warehouse->id, 'reserved_quantity' => 4, 'status' => 'RESERVED']);
        $headers = $this->authHeaders($this->makeTenantUser($tenant, self::ALL)[1]);

        $id = $this->reserve($wo, $product, 2, $headers);
        $this->postJson("/api/v1/app/part-requests/{$id}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertStatus(422)->assertJsonFragment(['message' => 'Line "Brake Pad Set": Insufficient stock: requested 2, available 1.']);
        $this->assertSame(4.0, (float) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
    }

    public function test_issue_checks_permission_warehouse_tenant_and_data_scope(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        $headers = $this->authHeaders($this->makeTenantUser($tenant, self::ALL)[1]);
        $id = $this->reserve($wo, $product, 1, $headers);
        $this->postJson("/api/v1/app/part-requests/{$id}/approve", [], $headers)->assertOk();

        [, $noIssue] = $this->makeTenantUser($tenant, ['part_request.view', 'part_request.approve', 'inventory.issue']);
        $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $warehouse->id], $this->authHeaders($noIssue))->assertForbidden();

        $foreign = $this->makeWarehouse($this->makeTenant());
        $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $foreign->id], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/part-requests/{$id}/issue", [], $headers)->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

        $otherWarehouse = $this->makeWarehouse($tenant);
        [, $scoped] = $this->makeTenantUser($tenant, self::ALL, ['WAREHOUSE' => $otherWarehouse->id]);
        $this->postJson("/api/v1/app/part-requests/{$id}/issue", ['warehouse_id' => $warehouse->id], $this->authHeaders($scoped))->assertForbidden();

        $this->assertSame('APPROVED', WorkOrderPartRequest::query()->findOrFail($id)->status);
    }

    public function test_decisions_require_their_permissions(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        $headers = $this->authHeaders($this->makeTenantUser($tenant, ['part_request.create'])[1]);
        $id = $this->reserve($wo, $product, 1, $headers);

        $this->postJson("/api/v1/app/part-requests/{$id}/approve", [], $headers)->assertForbidden();
        $this->postJson("/api/v1/app/part-requests/{$id}/reject", ['reason' => 'x'], $headers)->assertForbidden();
        $this->postJson("/api/v1/app/part-requests/{$id}/cancel", [], $headers)->assertForbidden();
        [, $viewer] = $this->makeTenantUser($tenant, ['part_request.view']);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $product->id, 'quantity_requested' => 1]]], $this->authHeaders($viewer))->assertForbidden();
    }

    public function test_index_filters_and_cross_tenant_access(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        $headers = $this->authHeaders($this->makeTenantUser($tenant, self::ALL)[1]);
        $first = $this->reserve($wo, $product, 1, $headers);
        $this->reserve($wo, $product, 2, $headers);
        $this->postJson("/api/v1/app/part-requests/{$first}/reject", ['reason' => 'duplicate'], $headers)->assertOk();

        $this->assertCount(1, $this->getJson("/api/v1/app/part-requests?work_order_id={$wo->id}&status=REQUESTED", $headers)->assertOk()->json('data'));
        $this->assertCount(2, $this->getJson("/api/v1/app/work-orders/{$wo->id}/part-requests", $headers)->assertOk()->json('data'));

        $otherTenant = $this->makeTenant(['code' => 'WPRX-'.Str::random(4)]);
        $this->grantModule($otherTenant, 'WORK_ORDER');
        [, $foreign] = $this->makeTenantUser($otherTenant, self::ALL);
        $this->postJson("/api/v1/app/part-requests/{$first}/approve", [], $this->authHeaders($foreign))->assertNotFound();
    }

    public function test_legacy_planned_lines_are_wrapped_in_an_approved_request_and_stay_issuable(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        $legacy = WorkOrderPlannedPart::query()->create([
            'tenant_id' => $tenant->id, 'work_order_id' => $wo->id, 'product_id' => $product->id, 'description' => 'Legacy brake pad',
            'quantity' => 3, 'planned_quantity' => 3, 'issued_quantity' => 1, 'status' => 'PARTIALLY_ISSUED',
        ]);
        WorkOrderPlannedPart::query()->create([
            'tenant_id' => $tenant->id, 'work_order_id' => $wo->id, 'product_id' => null, 'description' => 'Non-catalog',
            'quantity' => 1, 'planned_quantity' => 1, 'status' => 'PLANNED',
        ]);

        $migration = require database_path('migrations/2026_10_01_000003_add_issuing_to_work_order_part_requests.php');
        $migration->wrapLegacyPlannedParts();
        $migration->wrapLegacyPlannedParts();

        $wrapped = WorkOrderPartRequest::query()->where('work_order_id', $wo->id)->with('items')->get();
        $this->assertCount(1, $wrapped, 'Idempotent, and non-catalog lines are not wrapped.');
        $this->assertSame('APPROVED', $wrapped->first()->status);
        $this->assertSame([$legacy->id, 2.0], [$wrapped->first()->items->first()->planned_part_id, (float) $wrapped->first()->items->first()->quantity_approved]);

        $headers = $this->authHeaders($this->makeTenantUser($tenant, self::ALL)[1]);
        $this->postJson("/api/v1/app/part-requests/{$wrapped->first()->id}/issue", ['warehouse_id' => $warehouse->id], $headers)->assertOk();
        $this->assertSame([3.0, 'ISSUED'], [(float) $legacy->fresh()->issued_quantity, $legacy->fresh()->status]);
        $this->assertSame(1, DB::table('work_order_part_request_items')->where('planned_part_id', $legacy->id)->count());
    }
}
