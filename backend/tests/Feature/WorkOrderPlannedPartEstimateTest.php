<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The doc's true "Planned Parts" tab — pure budgeting, distinct from
 * "Request Parts" (the old Planned Parts tab, renamed — see the
 * 2026_09_28_000004 migration's docblock). Never touches warehouse
 * stock; only feeds Estimated Parts Cost.
 */
class WorkOrderPlannedPartEstimateTest extends TestCase
{
    private function setUpWorkOrder(): array
    {
        $tenant = $this->makeTenant(['code' => 'PPE-'.Str::random(4)]);
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

        [, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create', 'maintenance_job.manage']);
        $headers = $this->authHeaders($token);

        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null);

        return [$tenant, $warehouse, $wo, $headers];
    }

    public function test_planned_part_estimate_can_be_added_and_deleted_without_touching_stock(): void
    {
        [$tenant, $warehouse, $wo, $headers] = $this->setUpWorkOrder();
        $product = $this->makeProduct($tenant);
        app(InventoryService::class)->receive($warehouse, $product, 10, 25, 'OPENING', null, null, null);

        $response = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-part-estimates", [
            'product_id' => $product->id, 'quantity' => 3,
        ], $headers)->assertStatus(201);
        $estimateId = $response->json('data.id');

        $show = $this->getJson("/api/v1/app/work-orders/{$wo->id}", $headers)->assertOk();
        $this->assertCount(1, $show->json('data.planned_part_estimates'));
        // 3 * 25 (the product's average_unit_cost from the OPENING receipt) = 75.0000.
        $this->assertSame('75.0000', $show->json('data.estimated_parts_cost_computed'));

        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/planned-part-estimates/{$estimateId}", [], $headers)->assertOk();
        $show2 = $this->getJson("/api/v1/app/work-orders/{$wo->id}", $headers)->assertOk();
        $this->assertCount(0, $show2->json('data.planned_part_estimates'));
        $this->assertNull($show2->json('data.estimated_parts_cost_computed'));
    }

    public function test_planned_part_estimate_only_accepts_sparepart_tire_or_consumable(): void
    {
        [$tenant, , $wo, $headers] = $this->setUpWorkOrder();
        $toolCategory = $this->makeProductCategory(['item_type' => 'TOOL']);
        $tool = $this->makeProduct($tenant, $toolCategory, null, ['product_type' => 'TOOL']);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-part-estimates", [
            'product_id' => $tool->id, 'quantity' => 1,
        ], $headers)->assertStatus(422);
    }

    public function test_planned_part_estimate_does_not_require_the_work_order_to_be_executable(): void
    {
        // Unlike Reserve/Issue/Consume/Return, adding a budgeting-only estimate is allowed
        // through the whole planning window, including while still DRAFT.
        [, , $wo, $headers] = $this->setUpWorkOrder();
        $tenant = Tenant::query()->find($wo->tenant_id);
        $product = $this->makeProduct($tenant);
        $this->assertSame('DRAFT', $wo->status);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-part-estimates", [
            'product_id' => $product->id, 'quantity' => 1,
        ], $headers)->assertStatus(201);
    }

    public function test_planned_part_estimate_requires_permission(): void
    {
        [$tenant, , $wo] = $this->setUpWorkOrder();
        $product = $this->makeProduct($tenant);
        [, $noPermToken] = $this->makeTenantUser($tenant, ['work_order.view']);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-part-estimates", [
            'product_id' => $product->id, 'quantity' => 1,
        ], $this->authHeaders($noPermToken))->assertStatus(403);
    }
}
