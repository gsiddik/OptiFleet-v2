<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tire History popup data: actual installation / rotation / inspection records, oldest first; a
 * section without records comes back empty (the popup hides it).
 */
class TireHistoryTest extends TestCase
{
    public function test_history_lists_actual_records_chronologically_and_leaves_missing_sections_empty(): void
    {
        $tenant = $this->makeTenant(['code' => 'THI-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        foreach (['VEHICLE', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $vehicle = $this->makeVehicle($tenant, $this->makeBranch($tenant), $this->makeVehicleCategory(), ['registration_number' => 'B 12 THI']);
        [, $token] = $this->makeTenantUser($tenant, ['tire.view', 'tire.install', 'tire.inspect', 'tire.remove']);
        $headers = $this->authHeaders($token);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'HIST-1', 'current_status' => 'IN_STOCK']);

        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FL', 'odometer' => 1000], $headers)->assertStatus(201);
        $this->travel(1)->days();
        $this->postJson("/api/v1/app/tires/{$tire->id}/inspect", ['tread_depth_mm' => 8.5, 'condition' => 'good'], $headers)->assertSuccessful();
        $this->travel(1)->days();
        $this->postJson("/api/v1/app/tires/{$tire->id}/inspect", ['tread_depth_mm' => 7.25], $headers)->assertSuccessful();
        $this->travel(1)->days();
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'worn', 'disposition' => 'REUSE', 'odometer' => 4000], $headers)->assertSuccessful();

        $history = $this->getJson("/api/v1/app/tires/{$tire->id}/history", $headers)->assertOk()->json('data');
        $this->assertCount(1, $history['installations']);
        $this->assertSame(['B 12 THI', 'FL', '1000.00', '4000.00', 'worn'], [
            $history['installations'][0]['vehicle']['registration_number'], $history['installations'][0]['position_code'],
            $history['installations'][0]['installation_odometer'], $history['installations'][0]['removal_odometer'], $history['installations'][0]['removal_reason'],
        ]);
        $this->assertNotNull($history['installations'][0]['removed_at']);
        $this->assertSame([], $history['rotations'], 'no rotation happened → empty section');
        $this->assertSame(['8.50', '7.25'], array_column($history['inspections'], 'tread_depth_mm'), 'oldest first');
        $this->assertTrue($history['inspections'][0]['inspected_at'] < $history['inspections'][1]['inspected_at']);
        $this->assertSame('REMOVED', $history['tire']['current_status']);

        // Another tenant cannot read it; tire.view is required.
        $other = $this->makeTenant(['code' => 'THO-'.Str::random(4)]);
        $this->grantModule($other, 'TIRE');
        [, $otherToken] = $this->makeTenantUser($other, ['tire.view']);
        $this->getJson("/api/v1/app/tires/{$tire->id}/history", $this->authHeaders($otherToken))->assertNotFound();
        [, $noView] = $this->makeTenantUser($tenant, ['tire.install']);
        $this->getJson("/api/v1/app/tires/{$tire->id}/history", $this->authHeaders($noView))->assertForbidden();
    }
}
