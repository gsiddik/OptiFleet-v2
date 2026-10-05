<?php

namespace Tests\Feature;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issued Parts Total Cost = Final Consumed Quantity × Unit Cost (backend-computed). Returned and
 * still-outstanding quantity never counts. The stored issue-time snapshot (`total_cost`) is kept
 * for analytics (owner decision).
 */
class WorkOrderPartCostTest extends TestCase
{
    private function setUpWorkOrder(float $unitCost): array
    {
        $tenant = $this->makeTenant(['code' => 'WPC-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        app(InventoryService::class)->receive($warehouse, $product, 50, $unitCost, 'OPENING', null, null, null);
        $service = app(WorkOrderService::class);
        $wo = $service->start($this->withApprovedWorkspace($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null)
        ))))));
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'maintenance_job.manage', 'inventory.return']);

        return [$wo, $product, $warehouse, $this->authHeaders($token)];
    }

    public function test_total_cost_counts_only_the_consumed_quantity(): void
    {
        [$wo, $product, $warehouse, $headers] = $this->setUpWorkOrder(100);
        $part = $this->issueThroughPartRequest($wo, $product, 10, $warehouse);
        app(WorkOrderPartService::class)->consume($part, 7);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", ['quantity' => 3, 'condition' => 'UNUSED_NEW'], $headers)->assertOk();

        $line = collect($this->getJson("/api/v1/app/work-orders/{$wo->id}", $headers)->assertOk()->json('data.planned_parts'))->firstWhere('id', $part->id);
        $this->assertSame('100.0000', $line['average_unit_cost']);
        $this->assertSame('700.00', $line['consumed_total_cost'], '7 consumed × 100.00 — the 3 returned never count.');
        $this->assertSame('1000.0000', $line['total_cost'], 'The issue-time snapshot is unchanged.');
    }

    public function test_cost_follows_consumption_and_ignores_outstanding_quantity(): void
    {
        [$wo, $product, $warehouse] = $this->setUpWorkOrder(12.5);
        $part = $this->issueThroughPartRequest($wo, $product, 4, $warehouse);

        $this->assertSame('0.00', $part->fresh()->consumed_total_cost, 'Issued but not yet consumed costs nothing.');
        app(WorkOrderPartService::class)->consume($part, 1);
        $this->assertSame('12.50', $part->fresh()->consumed_total_cost);
        app(WorkOrderPartService::class)->consume($part->fresh(), 3);
        $this->assertSame('50.00', $part->fresh()->consumed_total_cost);
    }

    public function test_unit_cost_is_the_issue_snapshot_averaged_over_every_issue(): void
    {
        $line = new WorkOrderPlannedPart(['issued_quantity' => '3', 'consumed_quantity' => '2', 'total_cost' => '31.0000']);
        $this->assertSame('10.3333', $line->average_unit_cost);
        $this->assertSame('20.67', $line->consumed_total_cost);

        $notIssued = new WorkOrderPlannedPart(['issued_quantity' => '0', 'consumed_quantity' => '0', 'total_cost' => null]);
        $this->assertNull($notIssued->average_unit_cost);
        $this->assertNull($notIssued->consumed_total_cost);
    }
}
