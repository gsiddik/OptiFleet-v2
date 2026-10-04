<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'VEH-'.Str::random(4)]);
        $this->grantModule($tenant, 'ORGANIZATION');
        $this->grantModule($tenant, 'VEHICLE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();

        return [$tenant, $branch, $category];
    }

    private function setUpTenantWithBrandModel(): array
    {
        [$tenant, $branch, $category] = $this->setUpTenant();
        $brand = $this->makeVehicleBrand();
        $model = $this->makeVehicleModel($brand);

        return [$tenant, $branch, $category, $brand, $model];
    }

    public function test_vehicle_crud_works(): void
    {
        [$tenant, $branch, $category, $brand, $model] = $this->setUpTenantWithBrandModel();
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.create', 'vehicle.update']);

        $create = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id,
            'vehicle_category_id' => $category->id,
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'registration_number' => 'B 1234 XYZ',
            'current_odometer' => 1000,
        ], $this->authHeaders($token));
        $create->assertStatus(201);
        $id = $create->json('data.id');

        // brand/model text columns (kept for backward compatibility) are derived
        // server-side from the selected master data, never trusted from the client.
        $this->assertSame($brand->name, $create->json('data.brand'));
        $this->assertSame($model->name, $create->json('data.model'));

        $this->getJson("/api/v1/app/vehicles/{$id}", $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.registration_number', 'B 1234 XYZ');

        $this->putJson("/api/v1/app/vehicles/{$id}", ['current_odometer' => 5000], $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.current_odometer', '5000.00');

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_vehicle_physical_spec_fields_are_optional_and_stored(): void
    {
        [$tenant, $branch, $category, $brand, $model] = $this->setUpTenantWithBrandModel();
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.create', 'vehicle.update']);

        $create = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'registration_number' => 'B 5678 ABC',
            'color' => 'White', 'doors' => 4, 'seats' => 5, 'wheel_count' => 6,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('White', $create->json('data.color'));
        $this->assertSame(4, $create->json('data.doors'));
        $this->assertSame(6, $create->json('data.wheel_count'));

        $id = $create->json('data.id');
        $this->putJson("/api/v1/app/vehicles/{$id}", [
            'length_mm' => 5000, 'width_mm' => 1800, 'height_mm' => 1900,
            'fuel_tank_capacity_liters' => 80, 'engine_capacity_cc' => 2400,
            'suspension_type' => 'Leaf Spring', 'axle_count' => 2,
            'empty_weight_kg' => 1800, 'load_weight_kg' => 1000,
        ], $this->authHeaders($token))->assertOk()
            ->assertJsonPath('data.suspension_type', 'Leaf Spring')
            ->assertJsonPath('data.axle_count', 2);
    }

    public function test_registration_number_must_be_unique_per_tenant(): void
    {
        [$tenant, $branch, $category, $brand, $model] = $this->setUpTenantWithBrandModel();
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.create']);

        $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 9999 DUP']);

        $response = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id,
            'vehicle_category_id' => $category->id,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'registration_number' => 'B 9999 DUP',
        ], $this->authHeaders($token));

        $response->assertStatus(422);
    }

    public function test_nullable_vin_does_not_collide_across_vehicles(): void
    {
        [$tenant, $branch, $category, $brand, $model] = $this->setUpTenantWithBrandModel();
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.create']);

        $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1', 'vin' => null]);

        $response = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id,
            'vehicle_category_id' => $category->id,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'registration_number' => 'B 2',
        ], $this->authHeaders($token));

        $response->assertStatus(201);
    }

    public function test_vehicle_create_requires_brand_and_model_from_master_data(): void
    {
        [$tenant, $branch, $category] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.create']);

        $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id,
            'vehicle_category_id' => $category->id,
            'registration_number' => 'B 3',
        ], $this->authHeaders($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_brand_id', 'vehicle_model_id']);
    }

    public function test_vehicle_create_rejects_a_model_that_does_not_belong_to_the_selected_brand(): void
    {
        [$tenant, $branch, $category, $brand] = $this->setUpTenantWithBrandModel();
        $otherBrand = $this->makeVehicleBrand(['code' => 'VB-OTHER']);
        $mismatchedModel = $this->makeVehicleModel($otherBrand, ['code' => 'VM-OTHER']);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.create']);

        $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id,
            'vehicle_category_id' => $category->id,
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $mismatchedModel->id,
            'registration_number' => 'B 4',
        ], $this->authHeaders($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_model_id');
    }

    public function test_vehicle_tenant_isolation(): void
    {
        [$tenantA, $branchA, $category] = $this->setUpTenant();
        $tenantB = $this->makeTenant(['code' => 'VEH-B']);
        $this->grantModule($tenantB, 'VEHICLE');
        $branchB = $this->makeBranch($tenantB);

        $vehicleA = $this->makeVehicle($tenantA, $branchA, $category);
        $this->makeVehicle($tenantB, $branchB, $category, ['registration_number' => 'B-OTHER']);

        [, $tokenB] = $this->makeTenantUser($tenantB, ['vehicle.view']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($tokenB))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/app/vehicles/{$vehicleA->id}", $this->authHeaders($tokenB))
            ->assertStatus(404);
    }

    public function test_vehicle_branch_scope_restricts_access(): void
    {
        [$tenant, $branchA, $category] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);

        $vehicleA = $this->makeVehicle($tenant, $branchA, $category);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['registration_number' => 'B-SCOPE-2']);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view'], ['BRANCH' => $branchA->id]);

        $list = $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($vehicleA->id));
        $this->assertFalse($ids->contains($vehicleB->id));

        $this->getJson("/api/v1/app/vehicles/{$vehicleB->id}", $this->authHeaders($token))->assertStatus(403);
    }

    public function test_vehicle_assignment_creates_history(): void
    {
        [$tenant, $branchA, $category] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branchA, $category);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle.assign', 'vehicle.view']);

        $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/assign", [
            'branch_id' => $branchB->id,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame($branchB->id, $vehicle->fresh()->branch_id);

        $history = $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/assignments", $this->authHeaders($token))->assertOk();
        $this->assertCount(1, $history->json('data'));
        $this->assertSame($branchA->id, $history->json('data.0.from_branch_id'));
        $this->assertSame($branchB->id, $history->json('data.0.to_branch_id'));
        $this->assertSame($branchA->name, $history->json('data.0.from_branch.name'));
        $this->assertSame($branchB->name, $history->json('data.0.to_branch.name'));
    }

    public function test_vehicle_transfer_workflow_end_to_end(): void
    {
        [$tenant, $branchA, $category] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branchA, $category);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle.transfer', 'vehicle.view']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/vehicle-transfers', [
            'vehicle_id' => $vehicle->id, 'to_branch_id' => $branchB->id,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->postJson("/api/v1/app/vehicle-transfers/{$id}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'REQUESTED');
        $this->postJson("/api/v1/app/vehicle-transfers/{$id}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson("/api/v1/app/vehicle-transfers/{$id}/dispatch", [], $headers)->assertOk()->assertJsonPath('data.status', 'IN_TRANSIT');

        // Vehicle branch must NOT change before completion.
        $this->assertSame($branchA->id, $vehicle->fresh()->branch_id);

        $this->postJson("/api/v1/app/vehicle-transfers/{$id}/receive", [], $headers)->assertOk()->assertJsonPath('data.status', 'RECEIVED');
        $this->postJson("/api/v1/app/vehicle-transfers/{$id}/complete", [], $headers)->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame($branchB->id, $vehicle->fresh()->branch_id);

        // Invalid transition (already COMPLETED) is rejected.
        $this->postJson("/api/v1/app/vehicle-transfers/{$id}/submit", [], $headers)->assertStatus(422);
    }

    public function test_vehicle_transfer_list_is_branch_scoped(): void
    {
        [$tenant, $branchA, $category] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);
        $vehicleA = $this->makeVehicle($tenant, $branchA, $category);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['registration_number' => 'B-SCOPE-3']);

        [, $adminToken] = $this->makeTenantUser($tenant, ['vehicle.transfer', 'vehicle.view']);
        $adminHeaders = $this->authHeaders($adminToken);

        $this->postJson('/api/v1/app/vehicle-transfers', [
            'vehicle_id' => $vehicleA->id, 'to_branch_id' => $branchB->id,
        ], $adminHeaders)->assertStatus(201);
        $this->postJson('/api/v1/app/vehicle-transfers', [
            'vehicle_id' => $vehicleB->id, 'to_branch_id' => $branchA->id,
        ], $adminHeaders)->assertStatus(201);

        [, $scopedToken] = $this->makeTenantUser($tenant, ['vehicle.transfer', 'vehicle.view'], ['BRANCH' => $branchA->id]);

        $list = $this->getJson('/api/v1/app/vehicle-transfers', $this->authHeaders($scopedToken))->assertOk();
        $vehicleIds = collect($list->json('data'))->pluck('vehicle_id');
        $this->assertTrue($vehicleIds->contains($vehicleA->id));
        $this->assertFalse($vehicleIds->contains($vehicleB->id));
    }

    public function test_vehicle_transfer_requires_authorization_permission(): void
    {
        [$tenant, $branchA, $category] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branchA, $category);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view']); // no vehicle.transfer

        $this->postJson('/api/v1/app/vehicle-transfers', [
            'vehicle_id' => $vehicle->id, 'to_branch_id' => $branchB->id,
        ], $this->authHeaders($token))->assertStatus(403);
    }

    public function test_vehicle_document_upload_and_secured_download(): void
    {
        [$tenant, $branch, $category] = $this->setUpTenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.update', 'vehicle.view']);
        $headers = $this->authHeaders($token);

        $file = UploadedFile::fake()->create('registration.pdf', 100, 'application/pdf');
        $upload = $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/documents", [
            'file' => $file, 'document_type' => 'REGISTRATION',
        ], $headers)->assertStatus(201);

        $docId = $upload->json('data.id');
        // The stored path/disk are never exposed in the API response.
        $this->assertArrayNotHasKey('path', $upload->json('data'));
        $this->assertArrayNotHasKey('disk', $upload->json('data'));

        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/documents/{$docId}", $headers)->assertOk();

        // Unauthenticated direct access is rejected.
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/documents/{$docId}")->assertStatus(401);
    }

    /** Vehicle Tax + "Have an Expiry Date?" / "Need to be extended?": each checked box makes its date mandatory. */
    public function test_vehicle_document_expiry_and_extension_dates_gate_the_upload(): void
    {
        [$tenant, $branch, $category] = $this->setUpTenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.update', 'vehicle.view']);
        $headers = $this->authHeaders($token);
        $upload = fn (array $data) => $this->post("/api/v1/app/vehicles/{$vehicle->id}/documents", array_merge([
            'file' => UploadedFile::fake()->create('tax.pdf', 50, 'application/pdf'), 'document_type' => 'VEHICLE_TAX',
        ], $data), $headers + ['Accept' => 'application/json']);

        // Case A: nothing checked — uploaded, no dates stored even if sent.
        $plain = $upload(['has_expiry' => '0', 'needs_extension' => '0', 'extension_deadline' => '2027-01-01'])->assertStatus(201);
        $this->assertSame([false, null, false, null], [$plain->json('data.has_expiry'), $plain->json('data.expiry_date'), $plain->json('data.needs_extension'), $plain->json('data.extension_deadline')]);
        $this->assertSame('VEHICLE_TAX', $plain->json('data.document_type'));

        // Checked without its date — blocked; nothing stored.
        $upload(['has_expiry' => '1'])->assertStatus(422)->assertJsonValidationErrors('expiry_date');
        $upload(['has_expiry' => '1', 'expiry_date' => '2027-03-31', 'needs_extension' => '1'])->assertStatus(422)->assertJsonValidationErrors('extension_deadline');
        $upload(['needs_extension' => '1'])->assertStatus(422)->assertJsonValidationErrors('extension_deadline');
        $this->assertSame(1, $vehicle->documents()->count());

        // Case B: every shown date filled.
        $full = $upload(['has_expiry' => '1', 'expiry_date' => '2027-03-31', 'needs_extension' => '1', 'extension_deadline' => '2027-03-01'])->assertStatus(201);
        $this->assertSame([true, true], [$full->json('data.has_expiry'), $full->json('data.needs_extension')]);
        $this->assertStringStartsWith('2027-03-31', $full->json('data.expiry_date'));
        $this->assertStringStartsWith('2027-03-01', $full->json('data.extension_deadline'));

        // A legacy client sending only expiry_date keeps working.
        $legacy = $upload(['document_type' => 'INSURANCE', 'expiry_date' => '2027-06-30'])->assertStatus(201);
        $this->assertTrue($legacy->json('data.has_expiry'));
        $upload(['document_type' => 'PASSPORT'])->assertStatus(422)->assertJsonValidationErrors('document_type');
    }

    public function test_vehicle_document_upload_rejects_disallowed_mime_type(): void
    {
        [$tenant, $branch, $category] = $this->setUpTenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.update']);

        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');
        $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/documents", [
            'file' => $file, 'document_type' => 'OTHER',
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_vehicle_document_can_be_deleted(): void
    {
        [$tenant, $branch, $category] = $this->setUpTenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.update', 'vehicle.view']);
        $headers = $this->authHeaders($token);

        $file = UploadedFile::fake()->image('registration.jpg');
        $docId = $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/documents", [
            'file' => $file, 'document_type' => 'REGISTRATION',
        ], $headers)->json('data.id');

        $this->deleteJson("/api/v1/app/vehicles/{$vehicle->id}/documents/{$docId}", [], $headers)->assertOk();
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/documents/{$docId}", $headers)->assertStatus(404);
        $this->assertSoftDeleted('vehicle_documents', ['id' => $docId]);
    }

    public function test_vehicle_photo_can_be_uploaded_and_served_and_replaced(): void
    {
        [$tenant, $branch, $category, $brand, $model] = $this->setUpTenantWithBrandModel();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.update', 'vehicle.view']);
        $headers = $this->authHeaders($token);

        $first = UploadedFile::fake()->image('front.jpg', 200, 200);
        $upload = $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/photo", ['file' => $first], $headers)->assertOk();
        $this->assertTrue($upload->json('data.photo_available'));
        $this->assertArrayNotHasKey('photo_path', $upload->json('data'));
        $this->assertArrayNotHasKey('photo_disk', $upload->json('data'));

        $firstPath = $vehicle->fresh()->photo_path;
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($firstPath);

        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/photo", $headers)->assertOk();

        // Replacing the photo stores the new file and removes the old one only
        // after the vehicle record is updated to point at it.
        $second = UploadedFile::fake()->image('side.png', 200, 200);
        $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/photo", ['file' => $second], $headers)->assertOk();

        $secondPath = $vehicle->fresh()->photo_path;
        $this->assertNotSame($firstPath, $secondPath);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($secondPath);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($firstPath);
    }

    public function test_vehicle_photo_upload_rejects_disallowed_mime_type(): void
    {
        [$tenant, $branch, $category] = $this->setUpTenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.update']);

        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');
        $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/photo", ['file' => $file], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_vehicle_photo_is_blocked_outside_data_scope(): void
    {
        [$tenant, $branchA, $category] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branchB, $category);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.update', 'vehicle.view'], ['BRANCH' => $branchA->id]);
        $headers = $this->authHeaders($token);

        $file = UploadedFile::fake()->image('front.jpg', 200, 200);
        $this->postJson("/api/v1/app/vehicles/{$vehicle->id}/photo", ['file' => $file], $headers)->assertStatus(403);
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/photo", $headers)->assertStatus(403);
    }
}
