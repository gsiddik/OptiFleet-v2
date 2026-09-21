<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Next Improvement Tenant Portal - Products" Phase 5 (Supporting
 * Modules): Vehicle Purchase Month/Year, Vehicle Brand multi-value
 * "Brand Of" + Logo Upload, Worker Active/Inactive toggle, Workspace
 * vehicle-category sync (verifying the already-existing endpoint this
 * cycle's UI now calls).
 */
class ProductsCyclePhase5SupportingModulesTest extends TestCase
{
    private function setUpTenant(array $permissions): array
    {
        $tenant = $this->makeTenant(['code' => 'P5-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'CORE');
        $this->grantModule($tenant, 'WORKSHOP');
        [, $token] = $this->makeTenantUser($tenant, $permissions);

        return [$tenant, $token];
    }

    public function test_vehicle_purchase_month_and_year_are_optional_and_stored(): void
    {
        [$tenant, $token] = $this->setUpTenant(['vehicle.view', 'vehicle.create']);
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $brand = $this->makeVehicleBrand();
        $model = $this->makeVehicleModel($brand);

        $response = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'registration_number' => 'REG-'.Str::random(6),
            'purchase_month' => 6, 'purchase_year' => 2022,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame(6, $response->json('data.purchase_month'));
        $this->assertSame(2022, $response->json('data.purchase_year'));
    }

    public function test_vehicle_purchase_month_out_of_range_is_rejected(): void
    {
        [$tenant, $token] = $this->setUpTenant(['vehicle.view', 'vehicle.create']);
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $brand = $this->makeVehicleBrand();
        $model = $this->makeVehicleModel($brand);

        $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'registration_number' => 'REG-'.Str::random(6), 'purchase_month' => 13,
        ], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['purchase_month']);
    }

    public function test_vehicle_brand_usage_types_multi_value_is_stored(): void
    {
        [, $token] = $this->setUpTenant(['vehicle_brand.view', 'vehicle_brand.create']);

        $response = $this->postJson('/api/v1/app/vehicle-brands', [
            'code' => 'MULTI-'.Str::random(4), 'name' => 'Multi Brand', 'usage_types' => ['TRUCK', 'BUS'],
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame(['TRUCK', 'BUS'], $response->json('data.usage_types'));
    }

    public function test_vehicle_brand_logo_upload_and_secured_download(): void
    {
        [, $token] = $this->setUpTenant(['vehicle_brand.view', 'vehicle_brand.create', 'vehicle_brand.update']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'LOGO-'.Str::random(4), 'name' => 'Logo Brand'], $headers)
            ->assertStatus(201);
        $brandId = $create->json('data.id');
        $this->assertFalse($create->json('data.logo_available'));

        $file = UploadedFile::fake()->image('logo.png', 100, 100);
        $upload = $this->postJson("/api/v1/app/vehicle-brands/{$brandId}/logo", ['file' => $file], $headers)->assertOk();
        $this->assertTrue($upload->json('data.logo_available'));

        $this->get("/api/v1/app/vehicle-brands/{$brandId}/logo", $headers)->assertOk();
    }

    public function test_vehicle_brand_logo_upload_rejects_disallowed_mime_type(): void
    {
        [, $token] = $this->setUpTenant(['vehicle_brand.view', 'vehicle_brand.create', 'vehicle_brand.update']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/vehicle-brands', ['code' => 'BADLOGO-'.Str::random(4), 'name' => 'Bad Logo Brand'], $headers)
            ->assertStatus(201);

        $file = UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf');
        $this->postJson("/api/v1/app/vehicle-brands/{$create->json('data.id')}/logo", ['file' => $file], $headers)->assertStatus(422);
    }

    public function test_worker_can_be_activated_and_deactivated(): void
    {
        [$tenant, $token] = $this->setUpTenant(['worker.view', 'worker.manage']);
        $branch = $this->makeBranch($tenant);
        $worker = $this->makeWorker($tenant, $branch, null, ['status' => 'ACTIVE']);

        $this->putJson("/api/v1/app/workers/{$worker->id}", ['status' => 'INACTIVE'], $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');

        $this->putJson("/api/v1/app/workers/{$worker->id}", ['status' => 'ACTIVE'], $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_workspace_vehicle_category_sync_replaces_capacity_unit_free_text(): void
    {
        [$tenant, $token] = $this->setUpTenant(['workspace.view', 'workspace.manage']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/workspaces', [
            'workshop_id' => $workshop->id, 'code' => 'WS-'.Str::random(4), 'name' => 'Bay 1', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'capacity' => 2,
        ], $headers)->assertStatus(201);
        $workspaceId = $create->json('data.id');

        $this->postJson("/api/v1/app/workspaces/{$workspaceId}/vehicle-categories", ['vehicle_category_ids' => [$category->id]], $headers)
            ->assertOk();

        $show = $this->getJson("/api/v1/app/workspaces/{$workspaceId}", $headers)->assertOk();
        $this->assertSame($category->id, $show->json('data.vehicle_categories.0.id'));
    }
}
