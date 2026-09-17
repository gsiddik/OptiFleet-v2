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

        // Section 9: a new template of this type is seeded with a
        // system-mandatory "Odometer" item automatically.
        $this->assertCount(1, $create->json('data.items'));
        $this->assertSame('Odometer', $create->json('data.items.0.item_text'));
        $this->assertTrue($create->json('data.items.0.is_system'));

        $this->postJson("/api/v1/app/inspection-templates/{$templateId}/items", [
            'item_text' => 'Brake check', 'input_type' => 'PASS_FAIL', 'sequence' => 1,
        ], $headers)->assertStatus(201);

        $show = $this->getJson("/api/v1/app/inspection-templates/{$templateId}", $headers)->assertOk();
        $this->assertCount(2, $show->json('data.items'));
    }

    public function test_odometer_system_item_cannot_be_removed(): void
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
        $odometerItemId = $create->json('data.items.0.id');

        $this->deleteJson("/api/v1/app/inspection-templates/{$templateId}/items/{$odometerItemId}", [], $headers)
            ->assertStatus(403);
    }

    public function test_draft_template_is_not_selectable_for_a_new_inspection(): void
    {
        [$tenant, $branch, $category, $vehicle] = $this->setUpTenant();
        $template = \App\Domain\Inspection\Models\InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP', 'name' => 'Draft T', 'status' => 'DRAFT',
        ]);
        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create']);

        $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
        ], $this->authHeaders($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('inspection_template_id');
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

    public function test_inspection_captures_a_template_snapshot_at_creation(): void
    {
        [$tenant, , $category, $vehicle] = $this->setUpTenant();
        $template = \App\Domain\Inspection\Models\InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP', 'name' => 'T', 'status' => 'ACTIVE',
        ]);
        $template->items()->create(['item_text' => 'Odometer', 'input_type' => 'NUMBER', 'sequence' => 0, 'is_system' => true]);
        $template->items()->create(['item_text' => 'Brake', 'input_type' => 'PASS_FAIL', 'sequence' => 1]);

        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertCount(2, $create->json('data.template_snapshot'));

        // Later template edits must never alter this inspection's frozen snapshot.
        $template->items()->create(['item_text' => 'New item added later', 'input_type' => 'TEXT', 'sequence' => 2]);

        $show = $this->getJson("/api/v1/app/inspections/{$id}", $headers)->assertOk();
        $this->assertCount(2, $show->json('data.template_snapshot'));
    }

    public function test_inspection_log_records_status_transitions_and_maintenance_request_creation(): void
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
        $this->postJson("/api/v1/app/inspections/{$id}/maintenance-request", [], $headers)->assertStatus(201);

        $logs = $this->getJson("/api/v1/app/inspections/{$id}/logs", $headers)->assertOk();
        $actions = collect($logs->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('updated'));
        $this->assertTrue($actions->contains('maintenance_request_created'));
        foreach ($logs->json('data') as $entry) {
            $this->assertNotNull($entry['actor_name']);
        }
    }

    public function test_inspection_submission_raises_vehicle_odometer_but_never_lowers_it(): void
    {
        [$tenant, , $category, $vehicle] = $this->setUpTenant();
        $vehicle->update(['current_odometer' => 10000]);
        $template = \App\Domain\Inspection\Models\InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP', 'name' => 'T', 'status' => 'ACTIVE',
        ]);
        $item = $template->items()->create(['item_text' => 'Brake', 'input_type' => 'PASS_FAIL', 'sequence' => 1]);

        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
            'odometer_at_inspection' => 10500,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->postJson("/api/v1/app/inspections/{$id}/start", [], $headers);
        $this->postJson("/api/v1/app/inspections/{$id}/submit", [
            'results' => [['inspection_template_item_id' => $item->id, 'passed' => true]],
        ], $headers)->assertOk();

        $this->assertSame('10500.00', $vehicle->fresh()->current_odometer);

        // A second, lower-odometer inspection must never lower the vehicle's reading.
        $create2 = $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
            'odometer_at_inspection' => 9000,
        ], $headers)->assertStatus(201);
        $id2 = $create2->json('data.id');
        $this->postJson("/api/v1/app/inspections/{$id2}/start", [], $headers);
        $this->postJson("/api/v1/app/inspections/{$id2}/submit", [
            'results' => [['inspection_template_item_id' => $item->id, 'passed' => true]],
        ], $headers)->assertOk();

        $this->assertSame('10500.00', $vehicle->fresh()->current_odometer);
    }

    public function test_odometer_checklist_item_takes_precedence_over_the_header_field(): void
    {
        [$tenant, , $category, $vehicle] = $this->setUpTenant();
        $vehicle->update(['current_odometer' => 5000]);
        $template = \App\Domain\Inspection\Models\InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'inspection_type' => 'PRE_TRIP', 'name' => 'T', 'status' => 'ACTIVE',
        ]);
        $odometerItem = $template->items()->create(['item_text' => 'Odometer', 'input_type' => 'NUMBER', 'sequence' => 0, 'is_system' => true]);

        [, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit']);
        $headers = $this->authHeaders($token);

        // odometer_at_inspection defaults to the vehicle's current value (5000)
        // at creation time, before the technician has taken a fresh reading.
        $create = $this->postJson('/api/v1/app/inspections', [
            'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->postJson("/api/v1/app/inspections/{$id}/start", [], $headers);

        // The technician then submits a fresh, higher reading via the
        // mandatory Odometer checklist item — that must win.
        $this->postJson("/api/v1/app/inspections/{$id}/submit", [
            'results' => [['inspection_template_item_id' => $odometerItem->id, 'value_number' => 5250]],
        ], $headers)->assertOk();

        $this->assertSame('5250.00', $vehicle->fresh()->current_odometer);
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
