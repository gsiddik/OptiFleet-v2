<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\Workshop\Models\Workspace;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceReservationTest extends TestCase
{
    private function setUpWorkshop(): array
    {
        $tenant = $this->makeTenant(['code' => 'WSR-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'WORKSHOP');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);

        return [$tenant, $branch, $workshop];
    }

    public function test_workspace_crud(): void
    {
        [$tenant, , $workshop] = $this->setUpWorkshop();
        [, $token] = $this->makeTenantUser($tenant, ['workspace.view', 'workspace.manage', 'workspace.block']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/workspaces', [
            'workshop_id' => $workshop->id, 'code' => 'BAY-1', 'name' => 'Bay 1', 'workspace_type' => 'GENERAL_SERVICE_BAY',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->getJson("/api/v1/app/workspaces/{$id}", $headers)->assertOk()->assertJsonPath('data.status', 'AVAILABLE');
        $this->postJson("/api/v1/app/workspaces/{$id}/block", [], $headers)->assertOk()->assertJsonPath('data.status', 'BLOCKED');
        $this->postJson("/api/v1/app/workspaces/{$id}/unblock", [], $headers)->assertOk()->assertJsonPath('data.status', 'AVAILABLE');
    }

    public function test_workspace_capacity_is_optional_and_stored(): void
    {
        [$tenant, , $workshop] = $this->setUpWorkshop();
        [, $token] = $this->makeTenantUser($tenant, ['workspace.view', 'workspace.manage']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/workspaces', [
            'workshop_id' => $workshop->id, 'code' => 'BAY-2', 'name' => 'Heavy Bay', 'workspace_type' => 'HEAVY_VEHICLE_BAY',
            'capacity' => 2, 'capacity_unit' => 'vehicles',
        ], $headers)->assertStatus(201);
        $this->assertSame(2, $create->json('data.capacity'));
        $this->assertSame('vehicles', $create->json('data.capacity_unit'));

        $id = $create->json('data.id');
        $this->putJson("/api/v1/app/workspaces/{$id}", ['capacity' => 3], $headers)->assertOk()->assertJsonPath('data.capacity', 3);
    }

    public function test_reservation_lifecycle(): void
    {
        [$tenant, , $workshop] = $this->setUpWorkshop();
        $workspace = Workspace::query()->create([
            'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'BAY-1', 'name' => 'Bay 1',
            'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['workspace.view', 'workspace.reserve', 'workspace.approve']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id,
            'start_at' => now()->addHour()->toIso8601String(),
            'end_at' => now()->addHours(2)->toIso8601String(),
        ], $headers)->assertStatus(201)->assertJsonPath('data.status', 'RESERVED');
        $id = $create->json('data.id');

        // Approval model: RESERVED → APPROVED; Activate and manual Complete are retired.
        $this->postJson("/api/v1/app/workspace-reservations/{$id}/activate", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/workspace-reservations/{$id}/approve", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertNotNull($workspace->fresh()->reservations()->first()->approved_at);
        $this->postJson("/api/v1/app/workspace-reservations/{$id}/complete", [], $headers)->assertStatus(422);
        $this->assertSame('APPROVED', $workspace->reservations()->first()->status);
    }

    public function test_overlapping_reservation_is_rejected(): void
    {
        [$tenant, , $workshop] = $this->setUpWorkshop();
        $workspace = Workspace::query()->create([
            'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'BAY-1', 'name' => 'Bay 1',
            'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['workspace.reserve']);
        $headers = $this->authHeaders($token);

        $start = now()->addHour();
        $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id, 'start_at' => $start->toIso8601String(), 'end_at' => $start->copy()->addHours(2)->toIso8601String(),
        ], $headers)->assertStatus(201);

        // Overlapping window (starts inside the first reservation).
        $overlap = $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id,
            'start_at' => $start->copy()->addMinutes(30)->toIso8601String(),
            'end_at' => $start->copy()->addHours(3)->toIso8601String(),
        ], $headers);
        $overlap->assertStatus(422);

        // Back-to-back (non-overlapping) reservation is allowed.
        $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id,
            'start_at' => $start->copy()->addHours(2)->toIso8601String(),
            'end_at' => $start->copy()->addHours(3)->toIso8601String(),
        ], $headers)->assertStatus(201);
    }

    public function test_cancelled_reservation_frees_the_slot(): void
    {
        [$tenant, , $workshop] = $this->setUpWorkshop();
        $workspace = Workspace::query()->create([
            'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'BAY-1', 'name' => 'Bay 1',
            'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
        ]);
        [, $token] = $this->makeTenantUser($tenant, ['workspace.reserve']);
        $headers = $this->authHeaders($token);

        $start = now()->addHour();
        $first = $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id, 'start_at' => $start->toIso8601String(), 'end_at' => $start->copy()->addHours(2)->toIso8601String(),
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/workspace-reservations/{$first->json('data.id')}/cancel", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        // Same window is now free.
        $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id, 'start_at' => $start->toIso8601String(), 'end_at' => $start->copy()->addHours(2)->toIso8601String(),
        ], $headers)->assertStatus(201);
    }

    public function test_index_can_be_filtered_by_work_order_id(): void
    {
        [$tenant, $branch, $workshop] = $this->setUpWorkshop();
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $workspace = Workspace::query()->create([
            'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'BAY-1', 'name' => 'Bay 1',
            'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
        ]);
        $workOrder = WorkOrder::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'wo_number' => 'WO-FILTER-1', 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM', 'status' => 'DRAFT',
        ]);
        $otherWorkOrder = WorkOrder::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'wo_number' => 'WO-FILTER-2', 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM', 'status' => 'DRAFT',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['workspace.view', 'workspace.reserve']);
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id, 'work_order_id' => $workOrder->id,
            'start_at' => now()->addHour()->toIso8601String(), 'end_at' => now()->addHours(2)->toIso8601String(),
        ], $headers)->assertStatus(201);
        $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id, 'work_order_id' => $otherWorkOrder->id,
            'start_at' => now()->addHours(3)->toIso8601String(), 'end_at' => now()->addHours(4)->toIso8601String(),
        ], $headers)->assertStatus(201);

        $filtered = $this->getJson("/api/v1/app/workspace-reservations?work_order_id={$workOrder->id}", $headers)->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame($workOrder->id, $filtered->json('data.0.work_order_id'));
    }
}
