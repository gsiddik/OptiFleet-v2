<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tire History feed (Tire Operations / Used Tire Management recent activity) and the multi-status tire filter. */
class TireActivityTest extends TestCase
{
    private const PERMISSIONS = ['tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.remove', 'tire.inspect', 'tire.scrap'];

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TAC-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $vehicle = $this->makeVehicle($tenant, $this->makeBranch($tenant), $this->makeVehicleCategory(), ['registration_number' => 'B 42 ACT']);
        [, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Activity Tire']);

        return [$tenant, $vehicle, $product, $this->authHeaders($token)];
    }

    private function tire($tenant, $product, string $serial, string $status = 'IN_STOCK'): Tire
    {
        return Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => $status]);
    }

    public function test_feed_lists_tire_events_newest_first_with_type_and_search_filters(): void
    {
        [$tenant, $vehicle, $product, $headers] = $this->scenario();
        $tire = $this->tire($tenant, $product, 'SN-ACT-1');
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'FL', 'odometer' => 1000], $headers)->assertStatus(201);
        $this->travel(1)->minutes();
        $this->postJson("/api/v1/app/tires/{$tire->id}/rotate", ['to_position' => 'FR', 'odometer' => 2000], $headers)->assertStatus(201);
        $this->travel(1)->minutes();
        $this->postJson("/api/v1/app/tires/{$tire->id}/inspect", ['tread_depth_mm' => 7.5], $headers)->assertSuccessful();
        $this->travel(1)->minutes();
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'worn', 'disposition' => 'RETREAD', 'odometer' => 3000], $headers)->assertSuccessful();
        $this->travel(1)->minutes();
        $scrapped = $this->tire($tenant, $product, 'SN-ACT-SCRAP', 'HOLD');
        $this->postJson("/api/v1/app/tires/{$scrapped->id}/scrap", ['reason' => 'damaged'], $headers)->assertSuccessful();

        $feed = $this->getJson('/api/v1/app/tire-activity', $headers)->assertOk()->json('data');
        // A rotation opens the follow-up installation in the same instant; ties are ordered by type.
        $this->assertSame(['SCRAP', 'REMOVAL', 'INSPECTION', 'INSTALLATION', 'ROTATION', 'INSTALLATION'], array_column($feed, 'type'));
        $removal = $feed[1];
        $this->assertSame(['SN-ACT-1', 'B 42 ACT', 'RETREAD', 'Activity Tire'], [$removal['serial_number'], $removal['registration_number'], $removal['status'], $removal['product_name']]);
        $rotation = collect($feed)->firstWhere('type', 'ROTATION');
        $this->assertSame(['FL → FR', '2000.00'], [$rotation['position'], (string) $rotation['odometer']]);

        $this->assertSame(['ROTATION'], array_column($this->getJson('/api/v1/app/tire-activity?type[]=ROTATION', $headers)->json('data'), 'type'));
        $this->assertSame(['SCRAP'], array_column($this->getJson('/api/v1/app/tire-activity?search=scrap', $headers)->json('data'), 'type'));
        $this->getJson('/api/v1/app/tire-activity?type[]=BOGUS', $headers)->assertStatus(422);
        $this->getJson('/api/v1/app/tire-activity?per_page=2', $headers)->assertJsonPath('meta.total', 6)->assertJsonCount(2, 'data');
    }

    public function test_feed_is_tenant_isolated_and_permission_gated(): void
    {
        [$tenant, , $product, $headers] = $this->scenario();
        $this->tire($tenant, $product, 'SN-OWN', 'SCRAPPED');
        [, , , $otherHeaders] = $this->scenario();
        $this->assertSame([], $this->getJson('/api/v1/app/tire-activity', $otherHeaders)->assertOk()->json('data'));

        [, $noTire] = $this->makeTenantUser($tenant, ['product.view']);
        $this->getJson('/api/v1/app/tire-activity', $this->authHeaders($noTire))->assertForbidden();
    }

    public function test_tire_index_accepts_several_statuses(): void
    {
        [$tenant, , $product, $headers] = $this->scenario();
        $this->tire($tenant, $product, 'SN-S1');
        $this->tire($tenant, $product, 'SN-S2', 'RESERVED');
        $this->tire($tenant, $product, 'SN-S3', 'REMOVED');

        $serials = fn (string $q) => collect($this->getJson("/api/v1/app/tires?{$q}", $headers)->assertOk()->json('data'))->pluck('serial_number')->sort()->values()->all();
        $this->assertSame(['SN-S1', 'SN-S2'], $serials('current_status=IN_STOCK,RESERVED'));
        $this->assertSame(['SN-S3'], $serials('current_status=REMOVED'));
        $this->assertSame(['SN-S1', 'SN-S2', 'SN-S3'], $serials(''));
    }
}
