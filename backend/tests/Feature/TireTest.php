<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Organization\Models\Branch;
use App\Domain\Tire\Models\Tire;
use Illuminate\Support\Str;
use Tests\TestCase;

class TireTest extends TestCase
{
    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TIRE-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'TIRE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);

        return [$tenant, $vehicle, $product];
    }

    private function makeTirePermissions(): array
    {
        return ['tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap'];
    }

    public function test_tire_creation_and_installation(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'TIRE-SN-001', 'product_id' => $product->id, 'manufacturer' => 'Bridgestone', 'tire_size' => '295/80R22.5',
        ], $headers)->assertStatus(201);
        $tireId = $create->json('data.id');

        $this->postJson("/api/v1/app/tires/{$tireId}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT', 'odometer' => 10000,
        ], $headers)->assertStatus(201);

        $tire = Tire::query()->findOrFail($tireId);
        $this->assertSame('INSTALLED', $tire->current_status);
        $this->assertSame($vehicle->id, $tire->current_vehicle_id);
        $this->assertSame('FRONT_LEFT', $tire->current_position);
    }

    /** Phase G — G-11: tire_size was always a single free-text field; discrete spec fields are additive and optional. */
    public function test_tire_can_be_created_with_discrete_spec_fields_alongside_free_text_size(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());

        $create = $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'TIRE-SN-SPEC-001', 'product_id' => $product->id, 'tire_size' => '295/80R22.5',
            'section_width_mm' => 295, 'aspect_ratio' => 80, 'rim_diameter_inch' => 22.5, 'load_index' => 152, 'speed_rating' => 'L', 'ply_rating' => 18,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('295/80R22.5', $create->json('data.tire_size'));
        $this->assertSame(295, $create->json('data.section_width_mm'));
        $this->assertSame(80, $create->json('data.aspect_ratio'));
        $this->assertSame('22.5', $create->json('data.rim_diameter_inch'));
        $this->assertSame(152, $create->json('data.load_index'));
        $this->assertSame('L', $create->json('data.speed_rating'));
        $this->assertSame(18, $create->json('data.ply_rating'));
    }

    /** Final reconciliation (queued ADJUST): Construction/Tube Type were the only two VMS-listed spec fields still missing after G-11. */
    public function test_tire_construction_and_tube_type_are_optional_and_stored(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());

        $create = $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'TIRE-SN-CONSTR-001', 'product_id' => $product->id,
            'construction_type' => 'RADIAL', 'tube_type' => 'TUBELESS',
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('RADIAL', $create->json('data.construction_type'));
        $this->assertSame('TUBELESS', $create->json('data.tube_type'));

        $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'TIRE-SN-CONSTR-002', 'product_id' => $product->id,
        ], $this->authHeaders($token))
            ->assertStatus(201)
            ->assertJsonPath('data.construction_type', null)
            ->assertJsonPath('data.tube_type', null);

        $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'TIRE-SN-CONSTR-003', 'product_id' => $product->id, 'construction_type' => 'INVALID',
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_tire_can_still_be_created_without_any_discrete_spec_fields(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());

        $create = $this->postJson('/api/v1/app/tires', [
            'serial_number' => 'TIRE-SN-SPEC-002', 'product_id' => $product->id, 'tire_size' => '295/80R22.5',
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertNull($create->json('data.section_width_mm'));
    }

    public function test_duplicate_active_position_installation_is_rejected(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tireA = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-A', 'current_status' => 'IN_STOCK']);
        $tireB = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-B', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tireA->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tireB->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
        ], $headers)->assertStatus(422);
    }

    public function test_same_tire_cannot_be_installed_on_two_vehicles(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        $branch = Branch::query()->where('tenant_id', $tenant->id)->first();
        $category = VehicleCategory::query()->first();
        $vehicle2 = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'REG-OTHER']);
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-DUP', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT',
        ], $headers)->assertStatus(201);

        // Tire is now INSTALLED, so the service's own status guard should reject a second install.
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle2->id, 'wheel_position' => 'FRONT_RIGHT',
        ], $headers)->assertStatus(422);
    }

    public function test_tire_rotation_records_position_history(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-ROT', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT', 'odometer' => 10000,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/tires/{$tire->id}/rotate", [
            'to_position' => 'REAR_RIGHT', 'odometer' => 15000,
        ], $headers)->assertStatus(201);

        $tire->refresh();
        $this->assertSame('REAR_RIGHT', $tire->current_position);
        $this->assertSame(1, $tire->rotations()->count());
    }

    public function test_tire_removal_calculates_usage_and_updates_status(): void
    {
        [$tenant, $vehicle, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-REM', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", [
            'vehicle_id' => $vehicle->id, 'wheel_position' => 'FRONT_LEFT', 'odometer' => 10000,
        ], $headers)->assertStatus(201);

        $removeResponse = $this->postJson("/api/v1/app/tires/{$tire->id}/remove", [
            'removal_reason' => 'Worn out', 'disposition' => 'SCRAP', 'odometer' => 45000,
        ], $headers)->assertStatus(201);

        $installation = $tire->installations()->first();
        $usage = 45000 - (float) $installation->installation_odometer;
        $this->assertSame(35000.0, $usage);

        $tire->refresh();
        $this->assertSame('SCRAPPED', $tire->current_status);
        $this->assertNull($tire->current_vehicle_id);
    }

    public function test_tire_scrap_endpoint(): void
    {
        [$tenant, , $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $headers = $this->authHeaders($token);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-SCRAP', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/scrap", ['reason' => 'Damaged beyond repair'], $headers)->assertOk();

        $tire->refresh();
        $this->assertSame('SCRAPPED', $tire->current_status);
    }

    public function test_branch_scoped_user_cannot_see_or_access_tire_installed_in_another_branch(): void
    {
        $tenant = $this->makeTenant(['code' => 'TIREB-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'TIRE');
        $branchA = $this->makeBranch($tenant, ['code' => 'BR-A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'BR-B']);
        $category = $this->makeVehicleCategory();
        $vehicleA = $this->makeVehicle($tenant, $branchA, $category, ['registration_number' => 'REG-A']);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['registration_number' => 'REG-B']);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);

        [, $ownerToken] = $this->makeTenantUser($tenant, $this->makeTirePermissions());
        $tireA = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-BR-A', 'current_status' => 'IN_STOCK']);
        $tireB = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-BR-B', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tireA->id}/install", ['vehicle_id' => $vehicleA->id, 'wheel_position' => 'FRONT_LEFT'], $this->authHeaders($ownerToken))->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tireB->id}/install", ['vehicle_id' => $vehicleB->id, 'wheel_position' => 'FRONT_LEFT'], $this->authHeaders($ownerToken))->assertStatus(201);

        [, $scopedToken] = $this->makeTenantUser($tenant, ['tire.view'], ['BRANCH' => $branchA->id]);
        $list = $this->getJson('/api/v1/app/tires', $this->authHeaders($scopedToken))->assertOk();
        $serials = collect($list->json('data'))->pluck('serial_number');
        $this->assertTrue($serials->contains('SN-BR-A'));
        $this->assertFalse($serials->contains('SN-BR-B'));

        $this->getJson("/api/v1/app/tires/{$tireB->id}", $this->authHeaders($scopedToken))->assertStatus(403);
    }
}
