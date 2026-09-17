<?php

namespace Tests\Feature;

use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MasterData\Models\ComponentGroup;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enhancement Section 10-11: PREVENTIVE/PERIODIC package general fields,
 * threshold semantics, checkbox-based items lifecycle, and the
 * package_snapshot historical-integrity mechanism.
 */
class MaintenancePackageEnhancementTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'MPE-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['current_odometer' => 10000]);

        return [$tenant, $branch, $category, $vehicle];
    }

    public function test_new_package_workflow_rejects_legacy_maintenance_types(): void
    {
        [$tenant] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.manage']);

        $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-LEGACY', 'name' => 'Legacy', 'maintenance_type' => 'CORRECTIVE',
        ], $this->authHeaders($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('maintenance_type');
    }

    public function test_preventive_package_requires_a_positive_primary_threshold(): void
    {
        [$tenant] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.manage']);
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-2', 'name' => 'No threshold', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 0,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('threshold_km');

        $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-3', 'name' => 'Valid', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 5000,
        ], $headers)->assertStatus(201);
    }

    public function test_preventive_equivalent_thresholds_normalize_zero_to_null(): void
    {
        [$tenant] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.manage']);

        $create = $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-4', 'name' => 'Equivalents', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 5000,
            'threshold_days' => 30, 'threshold_month' => 0, 'threshold_engine_hour' => null,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame(30, $create->json('data.threshold_days'));
        $this->assertNull($create->json('data.threshold_month'));
        $this->assertNull($create->json('data.threshold_engine_hour'));
    }

    public function test_periodic_package_requires_schedule_period_and_rejects_km_or_engine_hour_period(): void
    {
        [$tenant] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.manage']);
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-5', 'name' => 'No period', 'maintenance_type' => 'PERIODIC', 'period_by' => 'MONTH',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('schedule_period');

        $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-6', 'name' => 'Bad unit', 'maintenance_type' => 'PERIODIC', 'period_by' => 'ODOMETER', 'schedule_period' => 3,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('period_by');

        $create = $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-7', 'name' => 'Good', 'maintenance_type' => 'PERIODIC', 'period_by' => 'MONTH', 'schedule_period' => 3,
        ], $headers)->assertStatus(201);
        $this->assertSame(3, $create->json('data.schedule_period'));
        $this->assertNull($create->json('data.threshold_km'));
    }

    public function test_draft_package_activation_requires_at_least_one_item_and_saves_atomically(): void
    {
        [$tenant] = $this->setUpTenant();
        $group = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-A', 'name' => 'Engine', 'is_system' => true, 'status' => 'ACTIVE']);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.manage']);
        $headers = $this->authHeaders($token);

        $packageId = $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-8', 'name' => 'Draft', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 5000,
        ], $headers)->json('data.id');

        $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/activate", [], $headers)
            ->assertStatus(422);

        $activate = $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/activate", [
            'component_group_ids' => [$group->id],
        ], $headers)->assertOk();

        $this->assertSame('ACTIVE', $activate->json('data.status'));
        $this->assertCount(1, $activate->json('data.component_groups'));
    }

    public function test_updating_active_package_items_flips_to_inactive_only_when_changed(): void
    {
        [$tenant] = $this->setUpTenant();
        $groupA = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-B', 'name' => 'Brakes', 'is_system' => true, 'status' => 'ACTIVE']);
        $groupB = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-C', 'name' => 'Tires', 'is_system' => true, 'status' => 'ACTIVE']);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.manage']);
        $headers = $this->authHeaders($token);

        $packageId = $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-9', 'name' => 'Active pkg', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 5000,
        ], $headers)->json('data.id');
        $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/activate", ['component_group_ids' => [$groupA->id]], $headers)->assertOk();

        // No-op update (same selection) must not flip status.
        $noop = $this->putJson("/api/v1/app/maintenance-policies/{$packageId}/items", [
            'component_group_ids' => [$groupA->id],
        ], $headers)->assertOk();
        $this->assertSame('ACTIVE', $noop->json('data.status'));

        // A real change flips the package to Inactive (Archived).
        $changed = $this->putJson("/api/v1/app/maintenance-policies/{$packageId}/items", [
            'component_group_ids' => [$groupA->id, $groupB->id],
        ], $headers)->assertOk();
        $this->assertSame('ARCHIVED', $changed->json('data.status'));

        // Reactivating keeps the already-saved selection without resubmitting it.
        $reactivated = $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/activate", [], $headers)->assertOk();
        $this->assertSame('ACTIVE', $reactivated->json('data.status'));
        $this->assertCount(2, $reactivated->json('data.component_groups'));
    }

    public function test_schedule_captures_a_package_snapshot_that_survives_later_package_changes(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        $group = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-D', 'name' => 'Suspension', 'is_system' => true, 'status' => 'ACTIVE']);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.view', 'maintenance_policy.manage', 'maintenance_schedule.view']);
        $headers = $this->authHeaders($token);

        $packageId = $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-10', 'name' => 'Snapshot pkg', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 5000,
        ], $headers)->json('data.id');
        $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/activate", ['component_group_ids' => [$group->id]], $headers);
        $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/intervals", [
            'trigger_type' => 'ODOMETER', 'odometer_km' => 5000, 'tolerance_km' => 500,
        ], $headers);

        $assign = $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/assign", ['vehicle_id' => $vehicle->id], $headers)->assertStatus(201);
        $scheduleId = $assign->json('data.schedule.id');

        $package = MaintenancePackage::query()->findOrFail($packageId);
        $this->assertSame([$group->id], $package->componentGroups()->pluck('component_groups.id')->all());

        $schedule = \App\Domain\MaintenancePolicy\Models\MaintenanceSchedule::query()->findOrFail($scheduleId);
        $this->assertSame(5000, $schedule->package_snapshot['threshold_km']);
        $this->assertSame([$group->id], $schedule->package_snapshot['component_group_ids']);

        // Later package edits must never alter the already-created schedule's snapshot.
        $newGroup = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-E', 'name' => 'Electrical', 'is_system' => true, 'status' => 'ACTIVE']);
        $this->putJson("/api/v1/app/maintenance-policies/{$packageId}/items", [
            'component_group_ids' => [$newGroup->id],
        ], $headers)->assertOk();

        $this->assertSame([$group->id], $schedule->fresh()->package_snapshot['component_group_ids']);
    }
}
