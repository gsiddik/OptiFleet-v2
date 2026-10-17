<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationSyncCursor;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Integration\Optinexus\VehicleOdometerService;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeNexus;
use Tests\TestCase;

class OdometerSyncTest extends TestCase
{
    private Tenant $tenant;

    private Vehicle $vehicle;

    private FakeNexus $nexus;

    /** Readings default to one hour apart, from a fixed past instant, so their order and speed are plausible. */
    private int $hours = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        app(CentralGovernance::class)->flushMemo();
        $this->nexus = (new FakeNexus)->install();
        config(['optinexus.gateway.feed_page_size' => 2]);

        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, [], 'ACTIVE', ['optifleet', 'optiradar']);
        $this->tenant = $this->makeTenant(['optinexus_tenant_id' => $nexusTenant]);
        $this->tenant->forceFill(['telematics_activated_at' => now()])->save();
        $this->vehicle = $this->makeVehicle($this->tenant, $this->makeBranch($this->tenant), $this->makeVehicleCategory(), ['current_odometer' => 10000]);
    }

    private function at(?int $hours = null): string
    {
        return CarbonImmutable::parse('2026-10-01T00:00:00Z')->addHours($hours ?? ++$this->hours)->toIso8601String();
    }

    private function item(string $km, string $kind = 'DEVICE_ODOMETER', ?string $at = null, ?Vehicle $vehicle = null, ?string $id = null): array
    {
        return [
            'reading_id' => $id ?? (string) Str::uuid(), 'vehicle_id' => ($vehicle ?? $this->vehicle)->id, 'registration_number' => 'X',
            'odometer_km' => $km, 'odometer_kind' => $kind, 'recorded_at' => $at ?? $this->at(), 'source' => 'optiradar', 'device_ref' => '7',
        ];
    }

    private function service(): VehicleOdometerService
    {
        return app(VehicleOdometerService::class);
    }

    private function odometer(): string
    {
        return (string) $this->vehicle->fresh()->current_odometer;
    }

    public function test_device_odometer_raises_the_vehicle_odometer(): void
    {
        $this->assertSame('applied', $this->service()->record($this->tenant->id, $this->item('12500.50')));

        $this->assertSame('12500.50', $this->odometer());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['vehicle_id' => $this->vehicle->id, 'applied' => true, 'previous_odometer' => '10000.00', 'effective_km' => '12500.50', 'disposition' => 'applied', 'unit' => 'km', 'origin' => 'optiradar']);
    }

    public function test_a_lower_reading_never_lowers_the_odometer(): void
    {
        // e.g. the user typed a higher value by hand in an inspection.
        $this->assertSame('stored', $this->service()->record($this->tenant->id, $this->item('9000')));

        $this->assertSame('10000.00', $this->odometer());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '9000.00', 'applied' => false, 'disposition' => 'stored']);
    }

    public function test_the_same_reading_is_applied_once(): void
    {
        $item = $this->item('11000');

        $this->assertSame('applied', $this->service()->record($this->tenant->id, $item));
        $this->assertSame('duplicate', $this->service()->record($this->tenant->id, $item));
        $this->assertSame(1, VehicleOdometerReading::query()->count());
    }

    public function test_manual_entry_and_telematics_coexist(): void
    {
        $this->service()->record($this->tenant->id, $this->item('11000', at: $this->at(1)));
        $this->vehicle->fresh()->update(['current_odometer' => 11500]); // manual entry, as inspections do

        $this->service()->record($this->tenant->id, $this->item('11200', at: $this->at(2)));
        $this->assertSame('11500.00', $this->odometer());

        $this->service()->record($this->tenant->id, $this->item('11800', at: $this->at(6)));
        $this->assertSame('11800.00', $this->odometer());
    }

    public function test_a_vehicle_of_another_tenant_is_never_touched(): void
    {
        $otherTenant = $this->makeTenant();
        $other = $this->makeVehicle($otherTenant, $this->makeBranch($otherTenant), $this->makeVehicleCategory(), ['current_odometer' => 500]);

        $this->assertSame('unknown_vehicle', $this->service()->record($this->tenant->id, $this->item('99999', vehicle: $other)));
        $this->assertSame('500.00', (string) $other->fresh()->current_odometer);
    }

    public function test_gps_distance_is_held_until_an_admin_calibrates(): void
    {
        $this->assertSame('held', $this->service()->record($this->tenant->id, $this->item('800', 'GPS_DISTANCE')));

        $this->assertSame('10000.00', $this->odometer());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['odometer_kind' => 'GPS_DISTANCE', 'applied' => false, 'effective_km' => null, 'disposition' => 'held']);
    }

    public function test_calibrating_with_the_real_odometer_applies_the_latest_reading(): void
    {
        $this->service()->record($this->tenant->id, $this->item('800', 'GPS_DISTANCE', '2026-10-08T01:00:00Z'));
        $this->service()->record($this->tenant->id, $this->item('850.25', 'GPS_DISTANCE', '2026-10-08T02:00:00Z'));
        $link = VehicleTelematicsLink::query()->firstOrFail();

        // Dashboard shows 10100 right now, when the GPS reading was 850.25 -> offset 9249.75.
        $link = $this->service()->calibrate($link, null, '10100', null);

        $this->assertSame('9249.75', (string) $link->odometer_offset_km);
        $this->assertSame('10100.00', $this->odometer());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '800.00', 'effective_km' => '10049.75', 'applied' => false, 'disposition' => 'stored']);
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '850.25', 'effective_km' => '10100.00', 'applied' => true, 'disposition' => 'applied']);
        $this->assertSame('optinexus', $this->vehicle->fresh()->odometer_source);
    }

    public function test_readings_after_calibration_apply_with_the_offset(): void
    {
        $this->service()->record($this->tenant->id, $this->item('800', 'GPS_DISTANCE'));
        $this->service()->calibrate(VehicleTelematicsLink::query()->firstOrFail(), '9200', null, null);

        $this->assertSame('10000.00', $this->odometer()); // 800 + 9200, not above current

        $this->assertSame('applied', $this->service()->record($this->tenant->id, $this->item('900.10', 'GPS_DISTANCE')));
        $this->assertSame('10100.10', $this->odometer());
    }

    public function test_calibration_never_lowers_the_odometer(): void
    {
        $this->service()->record($this->tenant->id, $this->item('800', 'GPS_DISTANCE'));
        $this->service()->calibrate(VehicleTelematicsLink::query()->firstOrFail(), '1000', null, null);

        $this->assertSame('10000.00', $this->odometer());
    }

    public function test_calibration_needs_a_reading_when_given_only_the_real_odometer(): void
    {
        $link = VehicleTelematicsLink::query()->create(['tenant_id' => $this->tenant->id, 'vehicle_id' => $this->vehicle->id, 'source' => 'optinexus', 'device_ref' => '7']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->calibrate($link, null, '10100', null);
    }

    public function test_decimal_arithmetic_is_exact(): void
    {
        $this->service()->record($this->tenant->id, $this->item('0.10', 'GPS_DISTANCE'));
        $this->service()->calibrate(VehicleTelematicsLink::query()->firstOrFail(), '10000.20', null, null);
        $this->service()->record($this->tenant->id, $this->item('0.20', 'GPS_DISTANCE'));

        $this->assertSame('10000.40', $this->odometer());
    }

    // --- scheduled sync against the fake gateway ---

    private function recent(int $minutesAgo): string
    {
        return now()->subMinutes($minutesAgo)->toIso8601String();
    }

    public function test_sync_publishes_vehicles_pulls_pages_and_saves_the_cursor(): void
    {
        $a = $this->item('10010', at: $this->recent(3));
        $b = $this->item('10020', at: $this->recent(2));
        $c = $this->item('10030', at: $this->recent(1));
        $this->nexus->gateway->page([$a, $b], 10)->page([$c], 11);

        $this->artisan('optinexus:sync')->assertSuccessful();

        $this->assertSame('10030.00', $this->odometer());
        $cursor = IntegrationSyncCursor::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(11, (int) $cursor->cursor);
        $this->assertNull($cursor->last_error);
        $this->assertSame('ok', $cursor->last_status);

        $this->assertSame($this->tenant->optinexus_tenant_id, $this->nexus->gateway->published[0]['nexus_tenant']);
        $this->assertSame($this->vehicle->id, $this->nexus->gateway->published[0]['vehicles'][0]['id']);
        $this->assertSame('10', $this->nexus->gateway->feedRequests[1]['cursor']);
    }

    public function test_sync_is_repeatable_without_double_applying(): void
    {
        $this->nexus->gateway->page([$this->item('11000', id: '11111111-1111-1111-1111-111111111111')], 5);

        $this->artisan('optinexus:sync')->assertSuccessful();
        IntegrationSyncCursor::query()->update(['cursor' => 0]); // pretend the cursor was lost
        $this->artisan('optinexus:sync')->assertSuccessful();

        $this->assertSame(1, VehicleOdometerReading::query()->count());
    }

    public function test_only_linked_active_activated_tenants_are_synced_and_failures_are_recorded(): void
    {
        $this->makeTenant(); // not linked to OptiNexus
        $this->nexus->gateway->fail('TENANT_NOT_SUBSCRIBED', 403);

        $this->artisan('optinexus:sync')->assertFailed();

        $this->assertSame(1, IntegrationSyncCursor::query()->count());
        $cursor = IntegrationSyncCursor::query()->first();
        $this->assertNotNull($cursor->last_error);
        $this->assertSame('TENANT_NOT_SUBSCRIBED', $cursor->last_error_code);
        $this->assertSame('error', $cursor->last_status);
        $this->assertSame(1, $cursor->consecutive_failures);
    }

    public function test_sync_does_nothing_when_disabled(): void
    {
        config(['optinexus.enabled' => false]);
        Http::fake();

        $this->artisan('optinexus:sync')->assertSuccessful();
        Http::assertNothingSent();
    }

    // --- calibration API ---

    private function adminToken(array $perms = ['telematics_link.view', 'telematics_link.manage']): string
    {
        $this->grantModule($this->tenant, 'VEHICLE');

        return $this->makeTenantUser($this->tenant, $perms)[1];
    }

    public function test_calibration_api_lists_and_calibrates(): void
    {
        $token = $this->adminToken();
        $this->service()->record($this->tenant->id, $this->item('800', 'GPS_DISTANCE'));
        $link = VehicleTelematicsLink::query()->firstOrFail();

        $this->getJson('/api/v1/app/telematics-links?needs_calibration=true', $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.0.calibrated', false)->assertJsonPath('data.0.needs_calibration', true)
            ->assertJsonPath('data.0.latest_reading.kind', 'GPS_DISTANCE')
            ->assertJsonPath('data.0.latest_reading.unit', 'km')
            ->assertJsonPath('data.0.latest_reading.quality', 'UNCALIBRATED');

        $this->putJson("/api/v1/app/telematics-links/{$link->id}/calibration", ['actual_odometer_km' => 10100], $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.calibrated', true)->assertJsonPath('data.needs_calibration', false)
            ->assertJsonPath('data.odometer_offset_km', '9300.00');

        $this->assertSame('10100.00', $this->odometer());
        $this->getJson('/api/v1/app/telematics-links?needs_calibration=true', $this->authHeaders($token))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_device_that_reports_a_real_odometer_does_not_need_calibration(): void
    {
        $token = $this->adminToken();
        $this->service()->record($this->tenant->id, $this->item('10500', 'DEVICE_ODOMETER'));

        $this->getJson('/api/v1/app/telematics-links', $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.0.calibrated', false)->assertJsonPath('data.0.needs_calibration', false);
        $this->getJson('/api/v1/app/telematics-links?needs_calibration=true', $this->authHeaders($token))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_calibration_api_requires_permission_and_respects_tenant_isolation(): void
    {
        $this->service()->record($this->tenant->id, $this->item('800', 'GPS_DISTANCE'));
        $link = VehicleTelematicsLink::query()->firstOrFail();

        $viewOnly = $this->adminToken(['telematics_link.view']);
        $this->putJson("/api/v1/app/telematics-links/{$link->id}/calibration", ['actual_odometer_km' => 10100], $this->authHeaders($viewOnly))->assertStatus(403);

        $otherTenant = $this->makeTenant();
        $this->grantModule($otherTenant, 'VEHICLE');
        [, $otherToken] = $this->makeTenantUser($otherTenant, ['telematics_link.view', 'telematics_link.manage']);
        $this->putJson("/api/v1/app/telematics-links/{$link->id}/calibration", ['actual_odometer_km' => 10100], $this->authHeaders($otherToken))->assertStatus(404);
        $this->getJson('/api/v1/app/telematics-links', $this->authHeaders($otherToken))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_calibration_api_validates_input(): void
    {
        $token = $this->adminToken();
        $this->service()->record($this->tenant->id, $this->item('800', 'GPS_DISTANCE'));
        $link = VehicleTelematicsLink::query()->firstOrFail();

        $this->putJson("/api/v1/app/telematics-links/{$link->id}/calibration", [], $this->authHeaders($token))->assertStatus(422);
        $this->putJson("/api/v1/app/telematics-links/{$link->id}/calibration", ['actual_odometer_km' => -5], $this->authHeaders($token))->assertStatus(422);
    }
}
