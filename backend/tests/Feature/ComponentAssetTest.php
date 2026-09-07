<?php

namespace Tests\Feature;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentRepair;
use Tests\TestCase;

class ComponentAssetTest extends TestCase
{
    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'CMP-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'COMPONENT');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $componentGroup = $this->makeComponentGroup();
        $product = $this->makeProduct($tenant);

        return [$tenant, $vehicle, $componentGroup, $product];
    }

    private function permissions(): array
    {
        return ['component_asset.view', 'component_asset.manage', 'component_asset.install', 'component_asset.remove', 'component_asset.replace'];
    }

    public function test_component_asset_installation(): void
    {
        [$tenant, $vehicle, $componentGroup, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/component-assets', [
            'product_id' => $product->id, 'component_group_id' => $componentGroup->id, 'serial_number' => 'BAT-001',
        ], $headers)->assertStatus(201);
        $assetId = $create->json('data.id');

        $this->postJson("/api/v1/app/component-assets/{$assetId}/install", [
            'vehicle_id' => $vehicle->id, 'position_location' => 'ENGINE_BAY', 'odometer' => 5000,
        ], $headers)->assertStatus(201);

        $asset = ComponentAsset::query()->findOrFail($assetId);
        $this->assertSame('INSTALLED', $asset->current_status);
        $this->assertSame($vehicle->id, $asset->current_vehicle_id);
    }

    public function test_duplicate_installation_of_same_asset_is_rejected(): void
    {
        [$tenant, $vehicle, $componentGroup, $product] = $this->setUpScenario();
        $branch = \App\Domain\Organization\Models\Branch::query()->where('tenant_id', $tenant->id)->first();
        $category = \App\Domain\MasterData\Models\VehicleCategory::query()->first();
        $vehicle2 = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'REG-CMP2']);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $asset = ComponentAsset::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'component_group_id' => $componentGroup->id,
            'serial_number' => 'BAT-DUP', 'current_status' => 'IN_STOCK',
        ]);

        $this->postJson("/api/v1/app/component-assets/{$asset->id}/install", [
            'vehicle_id' => $vehicle->id,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/component-assets/{$asset->id}/install", [
            'vehicle_id' => $vehicle2->id,
        ], $headers)->assertStatus(422);
    }

    public function test_component_replacement_preserves_history_and_links_assets(): void
    {
        [$tenant, $vehicle, $componentGroup, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $oldAsset = ComponentAsset::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'component_group_id' => $componentGroup->id,
            'serial_number' => 'BAT-OLD', 'current_status' => 'IN_STOCK',
        ]);
        $newAsset = ComponentAsset::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'component_group_id' => $componentGroup->id,
            'serial_number' => 'BAT-NEW', 'current_status' => 'IN_STOCK',
        ]);

        $this->postJson("/api/v1/app/component-assets/{$oldAsset->id}/install", [
            'vehicle_id' => $vehicle->id, 'position_location' => 'ENGINE_BAY',
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/component-assets/{$oldAsset->id}/replace", [
            'new_component_asset_id' => $newAsset->id, 'reason' => 'Failed battery', 'diagnosis_note' => 'Dead cell',
        ], $headers)->assertStatus(201);

        $oldAsset->refresh();
        $newAsset->refresh();
        $this->assertSame('REMOVED', $oldAsset->current_status);
        $this->assertNull($oldAsset->current_vehicle_id);
        $this->assertSame('INSTALLED', $newAsset->current_status);
        $this->assertSame($vehicle->id, $newAsset->current_vehicle_id);

        $removal = $oldAsset->removals()->first();
        $this->assertSame($newAsset->id, $removal->replaced_by_asset_id);
    }

    public function test_repair_lifecycle_updates_asset_status(): void
    {
        [$tenant, $vehicle, $componentGroup, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->permissions(), ['component_asset.manage']));
        $headers = $this->authHeaders($token);

        $asset = ComponentAsset::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'component_group_id' => $componentGroup->id,
            'serial_number' => 'BAT-REP', 'current_status' => 'IN_STOCK',
        ]);
        $this->postJson("/api/v1/app/component-assets/{$asset->id}/install", ['vehicle_id' => $vehicle->id], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/component-assets/{$asset->id}/remove", [
            'removal_reason' => 'Low performance', 'disposition' => 'REPAIR',
        ], $headers)->assertStatus(201);

        $asset->refresh();
        $this->assertSame('UNDER_REPAIR', $asset->current_status);

        $repairResponse = $this->postJson("/api/v1/app/component-assets/{$asset->id}/repairs", [
            'description' => 'Replace internal cells',
        ], $headers)->assertStatus(201);
        $repair = ComponentRepair::query()->findOrFail($repairResponse->json('data.id'));

        $this->postJson("/api/v1/app/component-assets/{$asset->id}/repairs/{$repair->id}/complete", [
            'outcome' => 'RECONDITIONED', 'cost' => 50,
        ], $headers)->assertOk();

        $asset->refresh();
        $this->assertSame('RECONDITIONED', $asset->current_status);
    }

    public function test_component_asset_tenant_isolation(): void
    {
        [$tenant, , , $product] = $this->setUpScenario();
        $asset = ComponentAsset::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'BAT-ISO', 'current_status' => 'IN_STOCK',
        ]);

        $otherTenant = $this->makeTenant(['code' => 'CMPB-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($otherTenant, 'COMPONENT');
        [, $otherToken] = $this->makeTenantUser($otherTenant, ['component_asset.view']);

        $this->getJson("/api/v1/app/component-assets/{$asset->id}", $this->authHeaders($otherToken))->assertStatus(404);
    }

    public function test_branch_scoped_user_cannot_see_or_access_component_asset_installed_in_another_branch(): void
    {
        $tenant = $this->makeTenant(['code' => 'CMPS-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'COMPONENT');
        $branchA = $this->makeBranch($tenant, ['code' => 'BR-A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'BR-B']);
        $category = $this->makeVehicleCategory();
        $vehicleA = $this->makeVehicle($tenant, $branchA, $category, ['registration_number' => 'REG-A']);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['registration_number' => 'REG-B']);
        $product = $this->makeProduct($tenant);

        [, $ownerToken] = $this->makeTenantUser($tenant, $this->permissions());
        $assetA = ComponentAsset::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'BAT-BR-A', 'current_status' => 'IN_STOCK']);
        $assetB = ComponentAsset::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'BAT-BR-B', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/component-assets/{$assetA->id}/install", ['vehicle_id' => $vehicleA->id], $this->authHeaders($ownerToken))->assertStatus(201);
        $this->postJson("/api/v1/app/component-assets/{$assetB->id}/install", ['vehicle_id' => $vehicleB->id], $this->authHeaders($ownerToken))->assertStatus(201);

        [, $scopedToken] = $this->makeTenantUser($tenant, ['component_asset.view'], ['BRANCH' => $branchA->id]);
        $list = $this->getJson('/api/v1/app/component-assets', $this->authHeaders($scopedToken))->assertOk();
        $serials = collect($list->json('data'))->pluck('serial_number');
        $this->assertTrue($serials->contains('BAT-BR-A'));
        $this->assertFalse($serials->contains('BAT-BR-B'));

        $this->getJson("/api/v1/app/component-assets/{$assetB->id}", $this->authHeaders($scopedToken))->assertStatus(403);
    }
}
