<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issuance & Return business rule: a Consumed part can never be returned through
 * Issuance & Return (backend-enforced, no stock movement); only the unconsumed
 * remainder of an issued line is returnable. Returns of components removed from the
 * unit go through Removed Components (see WorkOrderRemovedComponentTest).
 */
class WorkOrderConsumedReturnTest extends TestCase
{
    private function issuedPart(float $issueQty = 10): array
    {
        $tenant = $this->makeTenant(['code' => 'CNS-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INVENTORY'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant);
        app(InventoryService::class)->receive($warehouse, $product, 100, 15, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return']);
        $headers = $this->authHeaders($token);

        $service = app(WorkOrderService::class);
        $wo = $service->start($service->schedule($service->assign($service->approve($service->submit(
            $service->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null)
        )))));

        $partId = $this->issueThroughPartRequest($wo, $product, $issueQty, $warehouse)->id;

        return [$wo, WorkOrderPlannedPart::query()->findOrFail($partId), $headers];
    }

    private function returnMovements(string $partId): int
    {
        return StockMovement::query()->where('reference_type', WorkOrderPlannedPart::class)->where('reference_id', $partId)->where('movement_type', 'RETURN')->count();
    }

    public function test_consumed_part_cannot_be_returned_and_creates_no_inventory_movement(): void
    {
        [$wo, $part, $headers] = $this->issuedPart();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/consume", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'CONSUMED')->assertJsonPath('data.returnable_quantity', 0);

        foreach (['UNUSED_NEW', 'USED_GOOD'] as $condition) {
            $response = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
                'quantity' => 1, 'condition' => $condition,
            ], $headers)->assertStatus(422);
            $this->assertStringContainsString('Consumed parts cannot be returned', $response->json('message'));
        }

        $fresh = $part->fresh();
        $this->assertSame('CONSUMED', $fresh->status);
        $this->assertSame(0.0, (float) $fresh->returned_quantity);
        $this->assertSame(0, $this->returnMovements($part->id));
        $this->assertSame(0, WorkOrderPartReturn::query()->where('work_order_planned_part_id', $part->id)->count());
    }

    public function test_only_the_unconsumed_remainder_of_a_partially_consumed_line_is_returnable(): void
    {
        [$wo, $part, $headers] = $this->issuedPart(10);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/consume", ['quantity' => 6], $headers)
            ->assertOk()->assertJsonPath('data.returnable_quantity', 4);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", ['quantity' => 5, 'condition' => 'UNUSED_NEW'], $headers)
            ->assertStatus(422);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", ['quantity' => 4, 'condition' => 'UNUSED_NEW'], $headers)
            ->assertOk()->assertJsonPath('data.returnable_quantity', 0);

        $this->assertSame(1, $this->returnMovements($part->id));
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", ['quantity' => 1, 'condition' => 'UNUSED_NEW'], $headers)
            ->assertStatus(422);
        $this->assertSame(1, $this->returnMovements($part->id), 'No duplicate return movement.');
    }

    public function test_return_requires_inventory_return_permission(): void
    {
        [$wo, $part] = $this->issuedPart();
        [, $token] = $this->makeTenantUser($wo->tenant, ['maintenance_job.manage']);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", ['quantity' => 1, 'condition' => 'UNUSED_NEW'], $this->authHeaders($token))
            ->assertStatus(403);
    }
}
