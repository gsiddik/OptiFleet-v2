<?php

namespace Tests\Feature;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Services\TireService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G-17/G-35: Work Order closure guard against dangling parts and Tire activity.
 */
class WorkOrderClosureGuardTest extends TestCase
{
    private function fullPermissions(): array
    {
        return [
            'work_order.view', 'work_order.create', 'work_order.update', 'work_order.submit',
            'work_order.approve', 'work_order.assign', 'work_order.schedule', 'work_order.start',
            'work_order.pause', 'work_order.complete', 'work_order.close', 'work_order.cancel',
            'maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return',
            'tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap',
        ];
    }

    private function driveToInProgress(string $woId, array $headers): void
    {
        $this->postJson("/api/v1/app/work-orders/{$woId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/assign", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/schedule", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/start", [], $headers)->assertOk();
    }

    private function driveToQcPending(string $woId, array $headers): void
    {
        $this->driveToInProgress($woId, $headers);
        $this->postJson("/api/v1/app/work-orders/{$woId}/submit-to-qc", [], $headers)->assertOk();
    }

    private function setUp2(): array
    {
        $tenant = $this->makeTenant(['code' => 'WOG-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'TIRE');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant);
        app(InventoryService::class)->receive($warehouse, $product, 20, 15, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        return [$tenant, $branch, $workshop, $warehouse, $vehicle, $product, $headers];
    }

    public function test_work_order_without_dependencies_closes_normally(): void
    {
        [, , $workshop, , $vehicle, , $headers] = $this->setUp2();

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->driveToQcPending($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->postJson("/api/v1/app/work-orders/{$id}/close", [], $headers)->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_closure_is_blocked_while_a_planned_part_is_still_issued(): void
    {
        [, , $workshop, , $vehicle, $product, $headers] = $this->setUp2();

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->driveToInProgress($id, $headers);

        $addResponse = $this->postJson("/api/v1/app/work-orders/{$id}/planned-parts", [
            'product_id' => $product->id, 'description' => 'Oil filter', 'quantity' => 2,
        ], $headers)->assertStatus(201);
        $partId = $addResponse->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$id}/planned-parts/{$partId}/reserve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/planned-parts/{$partId}/issue", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/submit-to-qc", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertStatus(422);

        // Reconcile: consume the part, then closure succeeds.
        $this->postJson("/api/v1/app/work-orders/{$id}/planned-parts/{$partId}/consume", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->postJson("/api/v1/app/work-orders/{$id}/close", [], $headers)->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_closure_is_blocked_while_a_removed_tire_is_still_in_retread(): void
    {
        [$tenant, , $workshop, , $vehicle, $tireProductBase, $headers] = $this->setUp2();
        $tireProduct = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $woId = $create->json('data.id');

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $tireProduct->id, 'serial_number' => 'SN-CLOSURE-GUARD', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT'], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", [
            'removal_reason' => 'Worn tread', 'disposition' => 'RETREAD', 'work_order_id' => $woId,
        ], $headers)->assertStatus(201);

        $tire->refresh();
        $this->assertSame('RETREAD', $tire->current_status);

        $retread = app(TireService::class)->retread($tire, null, 500000, 'sent for retread');

        $this->driveToQcPending($woId, $headers);
        $this->postJson("/api/v1/app/work-orders/{$woId}/complete", [], $headers)->assertStatus(422);

        // Resolve: receive the retread, tire returns to IN_STOCK, closure now succeeds.
        app(TireService::class)->receiveRetread($retread);
        $tire->refresh();
        $this->assertSame('IN_STOCK', $tire->current_status);

        $this->postJson("/api/v1/app/work-orders/{$woId}/complete", [], $headers)->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->postJson("/api/v1/app/work-orders/{$woId}/close", [], $headers)->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }
}
