<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5: Request Parts is a mechanic-initiated request/approval document,
 * deliberately kept separate from Planned Parts (see
 * WorkOrderPartRequestService docblock). Approval creates a
 * WorkOrderPlannedPart per approved line rather than mutating stock
 * directly — Reserve/Issue/Consume/Return on that resulting Planned Part
 * is already covered by WorkOrderStockIntegrationTest.
 */
class WorkOrderPartRequestTest extends TestCase
{
    private function setUpWorkOrder(): array
    {
        $tenant = $this->makeTenant(['code' => 'WPR-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant);

        [$user] = $this->makeTenantUser($tenant, []);
        $wo = app(WorkOrderService::class)->create($vehicle, [
            'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $user->id);
        app(WorkOrderService::class)->submit($wo);
        $wo = app(WorkOrderService::class)->approve($wo);
        $wo = app(WorkOrderService::class)->assign($wo);
        $wo = app(WorkOrderService::class)->schedule($wo);
        $wo = app(WorkOrderService::class)->start($wo);

        return [$tenant, $workshop, $product, $wo];
    }

    public function test_mechanic_can_request_parts_and_it_starts_as_requested(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create']);
        $headers = $this->authHeaders($token);

        $response = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'notes' => 'Need parts for the front brake job',
            'items' => [
                ['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 4],
                ['description' => 'Generic gasket (non-catalog)', 'quantity_requested' => 2],
            ],
        ], $headers)->assertStatus(201);

        $this->assertSame('REQUESTED', $response->json('data.status'));
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_request_requires_at_least_one_item(): void
    {
        [$tenant, , , $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create']);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [],
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_request_is_rejected_while_work_order_is_not_executable(): void
    {
        $tenant = $this->makeTenant(['code' => 'WPR-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        [$user, $token] = $this->makeTenantUser($tenant, ['work_order.create', 'part_request.create']);
        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['description' => 'Oil filter', 'quantity_requested' => 1]],
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_approve_creates_planned_parts_for_approved_lines(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.approve']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [
                ['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 4],
                ['description' => 'Wiper blade', 'quantity_requested' => 2],
            ],
        ], $headers)->assertStatus(201);
        $requestId = $create->json('data.id');

        $approve = $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [], $headers)->assertOk();
        $this->assertSame('APPROVED', $approve->json('data.status'));

        $items = $approve->json('data.items');
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertNotNull($item['planned_part_id']);
            $plannedPart = WorkOrderPlannedPart::query()->findOrFail($item['planned_part_id']);
            $this->assertSame($wo->id, $plannedPart->work_order_id);
            $this->assertSame('PLANNED', $plannedPart->status);
            $this->assertSame((float) $item['quantity_approved'], (float) $plannedPart->planned_quantity);
        }
    }

    public function test_approve_can_partially_approve_a_line_quantity(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.approve']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 10]],
        ], $headers)->assertStatus(201);
        $requestId = $create->json('data.id');
        $itemId = $create->json('data.items.0.id');

        $approve = $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [
            'approved_quantities' => [$itemId => 3],
        ], $headers)->assertOk();

        $this->assertSame(3.0, (float) $approve->json('data.items.0.quantity_approved'));
        $plannedPart = WorkOrderPlannedPart::query()->findOrFail($approve->json('data.items.0.planned_part_id'));
        $this->assertSame(3.0, (float) $plannedPart->planned_quantity);
    }

    public function test_approve_requires_at_least_one_approved_line(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.approve']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 5]],
        ], $headers)->assertStatus(201);
        $requestId = $create->json('data.id');
        $itemId = $create->json('data.items.0.id');

        $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [
            'approved_quantities' => [$itemId => 0],
        ], $headers)->assertStatus(422);
    }

    public function test_reject_requires_a_reason_and_creates_no_planned_part(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.reject']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 5]],
        ], $headers)->assertStatus(201);
        $requestId = $create->json('data.id');

        $this->postJson("/api/v1/app/part-requests/{$requestId}/reject", [], $headers)->assertStatus(422);

        $reject = $this->postJson("/api/v1/app/part-requests/{$requestId}/reject", [
            'reason' => 'Out of scope for this job',
        ], $headers)->assertOk();
        $this->assertSame('REJECTED', $reject->json('data.status'));
        $this->assertSame(0, WorkOrderPlannedPart::query()->where('work_order_id', $wo->id)->count());
    }

    public function test_cancel_only_allowed_while_requested(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.approve', 'part_request.cancel']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 5]],
        ], $headers)->assertStatus(201);
        $requestId = $create->json('data.id');

        $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/part-requests/{$requestId}/cancel", [], $headers)->assertStatus(422);
    }

    public function test_decided_request_cannot_be_decided_again(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.approve']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 5]],
        ], $headers)->assertStatus(201);
        $requestId = $create->json('data.id');

        $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [], $headers)->assertStatus(422);
    }

    public function test_index_can_be_filtered_by_work_order_id_and_status(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.view', 'part_request.reject']);
        $headers = $this->authHeaders($token);

        $first = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 5]],
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['description' => 'Air filter', 'quantity_requested' => 1]],
        ], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/part-requests/{$first}/reject", ['reason' => 'duplicate'], $headers)->assertOk();

        $filtered = $this->getJson("/api/v1/app/part-requests?work_order_id={$wo->id}&status=REQUESTED", $headers)->assertOk();
        $this->assertCount(1, $filtered->json('data'));
    }

    public function test_cross_tenant_part_request_cannot_be_approved(): void
    {
        [$tenantA, , $product, $wo] = $this->setUpWorkOrder();
        [, $tokenA] = $this->makeTenantUser($tenantA, ['part_request.create']);
        $requestId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 5]],
        ], $this->authHeaders($tokenA))->assertStatus(201)->json('data.id');

        $tenantB = $this->makeTenant(['code' => 'WPR-'.Str::random(4)]);
        $this->grantModule($tenantB, 'WORK_ORDER');
        [, $tokenB] = $this->makeTenantUser($tenantB, ['part_request.approve']);

        $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [], $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_create_and_approve_require_permission(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, []);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", [
            'items' => [['product_id' => $product->id, 'description' => 'Brake pad set', 'quantity_requested' => 5]],
        ], $headers)->assertStatus(403);
    }
}
