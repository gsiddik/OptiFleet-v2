<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A tire on no vehicle and in no warehouse (REMOVED, HOLD, at a repair / retread partner) belongs
 * to the data scope of the branch of the vehicle it was last installed on.
 */
class TireRemovedBranchScopeTest extends TestCase
{
    public function test_removed_and_hold_tires_follow_the_branch_of_their_last_vehicle(): void
    {
        $tenant = $this->makeTenant(['code' => 'TRB-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branchA = $this->makeBranch($tenant, ['code' => 'BR-A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'BR-B']);
        $category = $this->makeVehicleCategory();
        $vehicleA = $this->makeVehicle($tenant, $branchA, $category, ['registration_number' => 'REG-A']);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['registration_number' => 'REG-B']);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        [, $ownerToken] = $this->makeTenantUser($tenant, ['tire.view', 'tire.manage', 'tire.install', 'tire.remove']);
        $owner = $this->authHeaders($ownerToken);

        $cycle = function (string $serial, array $vehicles, string $status) use ($tenant, $product, $owner): Tire {
            $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
            foreach ($vehicles as $i => $vehicle) {
                $tire->refresh()->update(['current_status' => 'IN_STOCK']);
                $this->travel($i + 1)->minutes();
                $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FL'], $owner)->assertStatus(201);
                $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'worn', 'disposition' => 'REUSE'], $owner)->assertSuccessful();
            }
            $tire->refresh()->update(['current_status' => $status]);

            return $tire->fresh();
        };
        $removedA = $cycle('SN-REM-A', [$vehicleA], 'REMOVED');
        $holdA = $cycle('SN-HOLD-A', [$vehicleA], 'HOLD');
        $movedToB = $cycle('SN-A-THEN-B', [$vehicleA, $vehicleB], 'REMOVED');
        $this->assertNull($removedA->current_vehicle_id);
        $this->assertNull($removedA->current_warehouse_id);

        [, $scopedToken] = $this->makeTenantUser($tenant, ['tire.view'], ['BRANCH' => $branchA->id]);
        $scoped = $this->authHeaders($scopedToken);

        $serials = collect($this->getJson('/api/v1/app/tires?per_page=100', $scoped)->assertOk()->json('data'))->pluck('serial_number');
        $this->assertTrue($serials->contains('SN-REM-A'));
        $this->assertTrue($serials->contains('SN-HOLD-A'));
        $this->assertFalse($serials->contains('SN-A-THEN-B'), 'the last vehicle decides the branch');

        foreach ([$removedA, $holdA] as $tire) {
            $this->getJson("/api/v1/app/tires/{$tire->id}", $scoped)->assertOk();
            $this->getJson("/api/v1/app/tires/{$tire->id}/history", $scoped)->assertOk();
            $this->getJson("/api/v1/app/tires/{$tire->id}/used-inspection/context", $scoped)->assertOk();
        }
        $this->getJson("/api/v1/app/tires/{$movedToB->id}", $scoped)->assertStatus(403);
        $this->getJson("/api/v1/app/tires/{$movedToB->id}/used-inspection/context", $scoped)->assertNotFound();

        $used = collect($this->getJson("/api/v1/app/tire-products/{$product->id}/inventory?category=USED", $scoped)->assertOk()->json('data'))->pluck('serial_number');
        $this->assertEqualsCanonicalizing(['SN-REM-A', 'SN-HOLD-A'], $used->all());

        [, $branchBToken] = $this->makeTenantUser($tenant, ['tire.view'], ['BRANCH' => $branchB->id]);
        $this->getJson("/api/v1/app/tires/{$movedToB->id}", $this->authHeaders($branchBToken))->assertOk();
        $this->getJson("/api/v1/app/tires/{$removedA->id}", $this->authHeaders($branchBToken))->assertStatus(403);
    }
}
