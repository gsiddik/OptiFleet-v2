<?php

namespace Tests\Feature;

use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use Tests\TestCase;

class WarrantyClaimTest extends TestCase
{
    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'WCL-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'WARRANTY');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        $warranty = Warranty::query()->create([
            'tenant_id' => $tenant->id, 'coverage_basis' => 'DATE', 'duration_months' => 12,
            'starts_at' => now()->subMonths(3), 'status' => 'ACTIVE',
        ]);

        return [$tenant, $vehicle, $warranty];
    }

    private function permissions(): array
    {
        return ['warranty.view', 'warranty_claim.create', 'warranty_claim.review', 'warranty_claim.approve'];
    }

    public function test_warranty_claim_full_lifecycle_to_settlement(): void
    {
        [$tenant, $vehicle, $warranty] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/warranty-claims', [
            'vehicle_id' => $vehicle->id, 'warranty_id' => $warranty->id,
            'failure_date' => now()->toDateString(), 'reason' => 'Engine failure under warranty',
        ], $headers)->assertStatus(201);
        $claim = WarrantyClaim::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/approve", ['note' => 'Confirmed covered'], $headers)->assertOk();
        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/repair", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/settle", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/close", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_warranty_claim_rejection_requires_note_and_stops_lifecycle(): void
    {
        [$tenant, $vehicle, $warranty] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/warranty-claims', [
            'vehicle_id' => $vehicle->id, 'warranty_id' => $warranty->id,
            'failure_date' => now()->toDateString(), 'reason' => 'Suspected misuse',
        ], $headers)->assertStatus(201);
        $claim = WarrantyClaim::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/review", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/reject", [], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/reject", ['note' => 'Not covered - misuse'], $headers)->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');

        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/settle", [], $headers)->assertStatus(422);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        [$tenant, $vehicle, $warranty] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/warranty-claims', [
            'vehicle_id' => $vehicle->id, 'warranty_id' => $warranty->id,
            'failure_date' => now()->toDateString(), 'reason' => 'Testing invalid transition',
        ], $headers)->assertStatus(201);
        $claim = WarrantyClaim::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/warranty-claims/{$claim->id}/settle", [], $headers)->assertStatus(422);
    }

    public function test_branch_scoped_user_cannot_see_other_branch_claims(): void
    {
        $tenant = $this->makeTenant(['code' => 'WCLB-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'WARRANTY');
        $branchA = $this->makeBranch($tenant, ['code' => 'BR-A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'BR-B']);
        $category = $this->makeVehicleCategory();
        $vehicleA = $this->makeVehicle($tenant, $branchA, $category, ['registration_number' => 'REG-A']);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['registration_number' => 'REG-B']);

        [, $ownerToken] = $this->makeTenantUser($tenant, $this->permissions());
        $this->postJson('/api/v1/app/warranty-claims', [
            'vehicle_id' => $vehicleA->id, 'failure_date' => now()->toDateString(), 'reason' => 'Branch A claim',
        ], $this->authHeaders($ownerToken))->assertStatus(201);
        $this->postJson('/api/v1/app/warranty-claims', [
            'vehicle_id' => $vehicleB->id, 'failure_date' => now()->toDateString(), 'reason' => 'Branch B claim',
        ], $this->authHeaders($ownerToken))->assertStatus(201);

        [, $scopedToken] = $this->makeTenantUser($tenant, ['warranty.view'], ['BRANCH' => $branchA->id]);
        $list = $this->getJson('/api/v1/app/warranty-claims', $this->authHeaders($scopedToken))->assertOk();
        $vehicleIds = collect($list->json('data'))->pluck('vehicle_id');

        $this->assertTrue($vehicleIds->contains($vehicleA->id));
        $this->assertFalse($vehicleIds->contains($vehicleB->id));
    }
}
