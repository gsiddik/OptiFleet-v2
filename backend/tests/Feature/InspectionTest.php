<?php

namespace Tests\Feature;

use Tests\TestCase;

class InspectionTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'INS-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INSPECTION');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        return [$tenant, $branch, $category, $vehicle];
    }

    public function test_inspection_template_dynamic_component_mapping(): void
    {
        [$tenant, , $category] = $this->setUpTenant();
        $this->grantModule($tenant, 'CORE');
        $engineGroup = $this->makeComponentGroup(['code' => 'CG-ENG-TEST']);
        $category->componentGroups()->sync([$engineGroup->id]);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle_category.view']);

        // Component groups for a category come dynamically from the DB mapping,
        // never a hardcoded list (Section 8).
        $response = $this->getJson("/api/v1/app/vehicle-categories/{$category->id}", $this->authHeaders($token));
        $response->assertOk();
        $groupIds = collect($response->json('data.component_groups'))->pluck('id');
        $this->assertTrue($groupIds->contains($engineGroup->id));
    }

    public function test_inspection_template_and_checklist_items(): void
    {
        [$tenant, , $category] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/inspection-templates', [
            'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP',
            'name' => 'Test Checklist',
        ], $headers)->assertStatus(201);
        $templateId = $create->json('data.id');

        $this->postJson("/api/v1/app/inspection-templates/{$templateId}/items", [
            'item_text' => 'Brake check', 'input_type' => 'PASS_FAIL', 'sequence' => 1,
        ], $headers)->assertStatus(201);

        $show = $this->getJson("/api/v1/app/inspection-templates/{$templateId}", $headers)->assertOk();
        $this->assertCount(1, $show->json('data.items'));
    }

    public function test_inspection_submission_workflow(): void
    {
        [$tenant, $branch, $category, $vehicle] = $this->setUpTenant();
        $template = \App\Domain\Inspection\Models\InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP', 'name' => 'T', 'status' => 'ACTIVE',
        ]);
        $item = $template->items()->create(['item_text' => 'Oil level', 'input_type' => 'PASS_FAIL', 'sequence' => 1]);

        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertSame('CREATED', $create->json('data.status'));

        $this->postJson("/api/v1/app/inspections/{$id}/start", [], $headers)->assertOk()->assertJsonPath('data.status', 'STARTED');

        $submit = $this->postJson("/api/v1/app/inspections/{$id}/submit", [
            'results' => [['inspection_template_item_id' => $item->id, 'passed' => true]],
        ], $headers)->assertOk();

        $this->assertSame('PASSED', $submit->json('data.status'));
    }

    public function test_failed_inspection_result_and_finding_severity(): void
    {
        [$tenant, , $category, $vehicle] = $this->setUpTenant();
        $template = \App\Domain\Inspection\Models\InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP', 'name' => 'T', 'status' => 'ACTIVE',
        ]);
        $item = $template->items()->create(['item_text' => 'Brake', 'input_type' => 'PASS_FAIL', 'sequence' => 1]);

        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->postJson("/api/v1/app/inspections/{$id}/start", [], $headers);

        $submit = $this->postJson("/api/v1/app/inspections/{$id}/submit", [
            'results' => [['inspection_template_item_id' => $item->id, 'passed' => false]],
            'findings' => [['severity' => 'CRITICAL', 'description' => 'Brake failure risk']],
        ], $headers)->assertOk();

        $this->assertSame('FAILED', $submit->json('data.status'));
        $this->assertCount(1, $submit->json('data.findings'));
    }

    public function test_failed_inspection_can_create_maintenance_request(): void
    {
        [$tenant, , $category, $vehicle] = $this->setUpTenant();
        $this->grantModule($tenant, 'MAINTENANCE');
        $template = \App\Domain\Inspection\Models\InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP', 'name' => 'T', 'status' => 'ACTIVE',
        ]);
        $item = $template->items()->create(['item_text' => 'Brake', 'input_type' => 'PASS_FAIL', 'sequence' => 1]);

        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->postJson("/api/v1/app/inspections/{$id}/start", [], $headers);
        $this->postJson("/api/v1/app/inspections/{$id}/submit", [
            'results' => [['inspection_template_item_id' => $item->id, 'passed' => false]],
        ], $headers)->assertOk();

        $mr = $this->postJson("/api/v1/app/inspections/{$id}/maintenance-request", [], $headers);
        $mr->assertStatus(201);
        $this->assertSame('INSPECTION', $mr->json('data.source_type'));
        $this->assertSame($id, $mr->json('data.source_inspection_id'));
    }

    public function test_inspection_denied_without_inspection_module_entitlement(): void
    {
        $tenant = $this->makeTenant(['code' => 'INS-NOMOD']);
        $this->grantModule($tenant, 'VEHICLE');
        // INSPECTION module deliberately NOT granted.
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create']);

        $this->getJson('/api/v1/app/inspections', $this->authHeaders($token))->assertStatus(403);
    }
}
