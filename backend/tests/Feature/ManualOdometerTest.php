<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\OdometerService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit A10: the odometer works fully by hand, without OptiNexus or OptiRadar. A person's entry, the corrections, the provenance of
 * the current value and the ledger behind it, and every other place that moves the odometer.
 */
class ManualOdometerTest extends TestCase
{
    private Tenant $tenant;

    private Vehicle $vehicle;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['optinexus.enabled' => false]);

        $this->tenant = $this->makeTenant();
        $this->grantModule($this->tenant, 'VEHICLE');
        $branch = $this->makeBranch($this->tenant);
        $this->vehicle = $this->makeVehicle($this->tenant, $branch, $this->makeVehicleCategory(), ['current_odometer' => 10000]);
        [, $this->token] = $this->makeTenantUser($this->tenant, ['vehicle.view', 'vehicle.update']);
    }

    private function headers(?string $token = null): array
    {
        return $this->authHeaders($token ?? $this->token);
    }

    private function url(string $suffix = 'odometer-readings', ?Vehicle $vehicle = null): string
    {
        return '/api/v1/app/vehicles/'.($vehicle ?? $this->vehicle)->id.'/'.$suffix;
    }

    private function odometer(): string
    {
        return (string) $this->vehicle->fresh()->current_odometer;
    }

    public function test_a_manual_entry_raises_the_odometer_and_records_where_it_came_from(): void
    {
        $measured = now()->subHour()->startOfSecond();

        $r = $this->postJson($this->url(), ['odometer_km' => '12500.5', 'measured_at' => $measured->toIso8601String(), 'note' => 'read at the gate'], $this->headers())
            ->assertStatus(201)
            ->assertJsonPath('data.outcome', 'applied')
            ->assertJsonPath('data.reading.source', 'manual')
            ->assertJsonPath('data.reading.kind', 'MANUAL_ENTRY')
            ->assertJsonPath('data.reading.reported_km', '12500.50')
            ->assertJsonPath('data.reading.previous_odometer', '10000.00')
            ->assertJsonPath('data.reading.unit', 'km')
            ->assertJsonPath('data.reading.quality', 'OK')
            ->assertJsonPath('data.reading.note', 'read at the gate')
            ->assertJsonPath('data.current.value', '12500.50')
            ->assertJsonPath('data.current.source', 'manual')
            ->assertJsonPath('data.current.provenance_known', true);

        $this->assertSame('12500.50', $this->odometer());
        $vehicle = $this->vehicle->fresh();
        $this->assertSame('manual', $vehicle->odometer_source);
        $this->assertTrue($vehicle->odometer_measured_at->equalTo($measured));
        $this->assertSame($r->json('data.reading.id'), $vehicle->odometer_reading_id);
        // measured_at and received_at are different facts: the entry was made now about a value read an hour ago.
        $reading = VehicleOdometerReading::query()->findOrFail($r->json('data.reading.id'));
        $this->assertTrue($reading->recorded_at->equalTo($measured));
        $this->assertTrue($reading->received_at->greaterThan($measured->addMinutes(50)));
        $this->assertNotNull($reading->entered_by);
    }

    public function test_a_legacy_odometer_has_no_invented_provenance(): void
    {
        $this->getJson($this->url('odometer'), $this->headers())->assertOk()
            ->assertJsonPath('data.current.value', '10000.00')
            ->assertJsonPath('data.current.source', null)
            ->assertJsonPath('data.current.measured_at', null)
            ->assertJsonPath('data.current.provenance_known', false)
            ->assertJsonPath('data.devices', []);
    }

    public function test_a_value_that_is_not_higher_changes_nothing_but_is_kept(): void
    {
        $this->postJson($this->url(), ['odometer_km' => 10000], $this->headers())
            ->assertStatus(201)->assertJsonPath('data.outcome', 'applied'); // first provenance for this value
        $this->postJson($this->url(), ['odometer_km' => 10000], $this->headers())
            ->assertStatus(201)->assertJsonPath('data.outcome', 'stored');

        $this->assertSame('10000.00', $this->odometer());
        $this->assertSame(2, VehicleOdometerReading::query()->count());
    }

    public function test_lowering_the_odometer_is_a_correction_that_needs_a_reason(): void
    {
        $this->postJson($this->url(), ['odometer_km' => 9000], $this->headers())
            ->assertStatus(422)->assertJsonPath('code', 'ODOMETER_BELOW_CURRENT')
            ->assertJsonPath('context.current_odometer', '10000.00')
            ->assertJsonStructure(['errors' => ['odometer_km']]);
        $this->postJson($this->url(), ['odometer_km' => 9000, 'correction_reason' => 'too short'], $this->headers())
            ->assertStatus(422)->assertJsonPath('code', 'ODOMETER_BELOW_CURRENT');
        $this->assertSame('10000.00', $this->odometer());
        $this->assertSame(0, VehicleOdometerReading::query()->count());

        $this->postJson($this->url(), ['odometer_km' => 9000, 'correction_reason' => 'The dashboard was replaced and reads 9000'], $this->headers())
            ->assertStatus(201)->assertJsonPath('data.outcome', 'correction')->assertJsonPath('data.reading.disposition', 'correction');

        $this->assertSame('9000.00', $this->odometer());
        $audit = AuditLog::query()->where('action', 'odometer_corrected')->where('resource_id', $this->vehicle->id)->firstOrFail();
        $this->assertSame('9000.00', $audit->new_values['current_odometer']);
        $this->assertSame('10000.00', $audit->old_values['current_odometer']);
        $this->assertStringContainsString('dashboard was replaced', $audit->new_values['reason']);
    }

    public function test_an_older_measurement_cannot_sit_above_a_newer_one_without_a_reason(): void
    {
        $this->postJson($this->url(), ['odometer_km' => 11000, 'measured_at' => now()->subHour()->toIso8601String()], $this->headers())->assertStatus(201);

        $this->postJson($this->url(), ['odometer_km' => 11500, 'measured_at' => now()->subDay()->toIso8601String()], $this->headers())
            ->assertStatus(422)->assertJsonPath('code', 'ODOMETER_TIME_CONFLICT');
        $this->assertSame('11000.00', $this->odometer());

        // An older, lower value is just history: kept, not applied.
        $this->postJson($this->url(), ['odometer_km' => 10500, 'measured_at' => now()->subDay()->toIso8601String(), 'correction_reason' => 'x'], $this->headers())
            ->assertStatus(422); // lower than current is still a correction and needs the reason
    }

    public function test_the_measurement_time_cannot_be_in_the_future(): void
    {
        $this->postJson($this->url(), ['odometer_km' => 11000, 'measured_at' => now()->addHour()->toIso8601String()], $this->headers())
            ->assertStatus(422)->assertJsonPath('code', 'ODOMETER_FUTURE_TIME');
        $this->assertSame('10000.00', $this->odometer());
    }

    public function test_unusable_values_are_refused_never_stored_as_zero(): void
    {
        foreach (['abc', '-5', '1e3', '', null] as $bad) {
            $this->postJson($this->url(), ['odometer_km' => $bad], $this->headers())->assertStatus(422);
        }
        $this->postJson($this->url(), ['odometer_km' => '99999999.999'], $this->headers())->assertStatus(422);
        $this->assertSame('10000.00', $this->odometer());
        $this->assertSame(0, VehicleOdometerReading::query()->count());
    }

    public function test_the_same_idempotency_key_records_once(): void
    {
        $key = (string) Str::uuid();

        $this->postJson($this->url(), ['odometer_km' => 10100, 'idempotency_key' => $key], $this->headers())->assertStatus(201)->assertJsonPath('data.duplicate', false);
        $this->postJson($this->url(), ['odometer_km' => 10100, 'idempotency_key' => $key], $this->headers())->assertStatus(200)->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, VehicleOdometerReading::query()->count());

        $this->postJson($this->url(), ['odometer_km' => 10200, 'idempotency_key' => $key], $this->headers())->assertStatus(409)->assertJsonPath('code', 'ODOMETER_IDEMPOTENCY_CONFLICT');
        $this->assertSame('10100.00', $this->odometer());
    }

    public function test_messages_follow_the_request_language_and_codes_stay_english(): void
    {
        [$user, $token] = $this->makeTenantUser($this->tenant, ['vehicle.view', 'vehicle.update']);
        $user->forceFill(['preferred_locale' => 'id'])->save();

        $r = $this->postJson($this->url(), ['odometer_km' => 9000], $this->headers($token))->assertStatus(422);

        $this->assertSame('ODOMETER_BELOW_CURRENT', $r->json('code'));
        $this->assertStringContainsString('Odometer saat ini 10000.00 km', $r->json('message'));
    }

    public function test_history_lists_every_source_newest_first_with_filters(): void
    {
        $this->postJson($this->url(), ['odometer_km' => 10100, 'measured_at' => now()->subHours(3)->toIso8601String()], $this->headers())->assertStatus(201);
        $this->postJson($this->url(), ['odometer_km' => 10200, 'measured_at' => now()->subHour()->toIso8601String()], $this->headers())->assertStatus(201);
        VehicleOdometerReading::query()->create([
            'tenant_id' => $this->tenant->id, 'vehicle_id' => $this->vehicle->id, 'source' => 'optinexus', 'source_reading_id' => (string) Str::uuid(), 'device_ref' => '7',
            'odometer_kind' => 'DEVICE_ODOMETER', 'reported_km' => '10150', 'effective_km' => '10150', 'previous_odometer' => '10100', 'applied' => false,
            'recorded_at' => now()->subHours(2), 'received_at' => now(), 'disposition' => 'stored',
        ]);

        $all = $this->getJson($this->url(), $this->headers())->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame(['10200.00', '10150.00', '10100.00'], collect($all->json('data'))->pluck('reported_km')->all());
        $this->assertSame('Tenant User', $all->json('data.0.entered_by.name'));

        $this->getJson($this->url().'?source=optinexus', $this->headers())->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.device_ref', '7');
    }

    public function test_permissions_data_scope_and_tenant_isolation(): void
    {
        [, $viewOnly] = $this->makeTenantUser($this->tenant, ['vehicle.view']);
        $this->getJson($this->url(), $this->headers($viewOnly))->assertOk();
        $this->postJson($this->url(), ['odometer_km' => 10100], $this->headers($viewOnly))->assertStatus(403);

        [, $nobody] = $this->makeTenantUser($this->tenant, ['workshop.view']);
        $this->getJson($this->url(), $this->headers($nobody))->assertStatus(403);

        $other = $this->makeTenant();
        $this->grantModule($other, 'VEHICLE');
        [, $otherToken] = $this->makeTenantUser($other, ['vehicle.view', 'vehicle.update']);
        $this->getJson($this->url(), $this->headers($otherToken))->assertStatus(404);
        $this->postJson($this->url(), ['odometer_km' => 99999], $this->headers($otherToken))->assertStatus(404);
        $this->assertSame('10000.00', $this->odometer());

        // A branch-scoped person cannot reach a vehicle of another branch.
        $branchA = $this->makeBranch($this->tenant);
        $branchB = $this->makeBranch($this->tenant);
        $vehicleB = $this->makeVehicle($this->tenant, $branchB, $this->makeVehicleCategory());
        [, $scoped] = $this->makeTenantUser($this->tenant, ['vehicle.view', 'vehicle.update'], ['BRANCH' => $branchA->id]);
        $this->postJson($this->url(vehicle: $vehicleB), ['odometer_km' => 10100], $this->headers($scoped))->assertStatus(403);
        $this->getJson($this->url('odometer', $vehicleB), $this->headers($scoped))->assertStatus(403);
    }

    public function test_the_module_gate_applies(): void
    {
        $tenant = $this->makeTenant();
        $vehicle = $this->makeVehicle($tenant, $this->makeBranch($tenant), $this->makeVehicleCategory());
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.update']);

        $this->postJson($this->url(vehicle: $vehicle), ['odometer_km' => 10100], $this->headers($token))->assertStatus(403);
    }

    public function test_updating_the_vehicle_routes_the_odometer_through_the_ledger(): void
    {
        $this->putJson('/api/v1/app/vehicles/'.$this->vehicle->id, ['color' => 'Red', 'current_odometer' => '10400'], $this->headers())->assertOk();
        $this->assertSame('10400.00', $this->odometer());
        $this->assertSame('manual', $this->vehicle->fresh()->odometer_source);
        $this->assertSame(1, VehicleOdometerReading::query()->count());

        // Unchanged value: nothing recorded.
        $this->putJson('/api/v1/app/vehicles/'.$this->vehicle->id, ['current_odometer' => '10400.00'], $this->headers())->assertOk();
        $this->assertSame(1, VehicleOdometerReading::query()->count());

        // Lowering without a reason is refused and nothing else in the same request is saved.
        $this->putJson('/api/v1/app/vehicles/'.$this->vehicle->id, ['color' => 'Blue', 'current_odometer' => '9000'], $this->headers())
            ->assertStatus(422)->assertJsonPath('code', 'ODOMETER_BELOW_CURRENT')->assertJsonStructure(['errors' => ['current_odometer']]);
        $this->assertSame('Red', $this->vehicle->fresh()->color);
        $this->assertSame('10400.00', $this->odometer());

        $this->putJson('/api/v1/app/vehicles/'.$this->vehicle->id, ['current_odometer' => '9000', 'odometer_correction_reason' => 'Wrong value typed at registration'], $this->headers())->assertOk();
        $this->assertSame('9000.00', $this->odometer());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['vehicle_id' => $this->vehicle->id, 'disposition' => 'correction']);
    }

    public function test_a_new_vehicle_with_a_starting_odometer_records_it_and_zero_records_nothing(): void
    {
        $branch = $this->makeBranch($this->tenant);
        $brand = $this->makeVehicleBrand();
        $model = $this->makeVehicleModel($brand);
        [, $creator] = $this->makeTenantUser($this->tenant, ['vehicle.view', 'vehicle.create']);
        $payload = fn (string $reg, ?string $odometer) => array_filter([
            'branch_id' => $branch->id, 'vehicle_category_id' => $this->vehicle->vehicle_category_id, 'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'registration_number' => $reg, 'current_odometer' => $odometer,
        ], fn ($v) => $v !== null);

        $with = $this->postJson('/api/v1/app/vehicles', $payload('B 1 AAA', '5000'), $this->headers($creator))->assertStatus(201);
        $this->assertSame('5000.00', $with->json('data.current_odometer'));
        $this->assertSame('manual', $with->json('data.odometer_source'));
        $this->assertDatabaseHas('vehicle_odometer_readings', ['vehicle_id' => $with->json('data.id'), 'note' => 'initial', 'previous_odometer' => '0.00']);

        $zero = $this->postJson('/api/v1/app/vehicles', $payload('B 2 AAA', '0'), $this->headers($creator))->assertStatus(201);
        $this->assertNull($zero->json('data.odometer_source'));
        $this->assertDatabaseMissing('vehicle_odometer_readings', ['vehicle_id' => $zero->json('data.id')]);
    }

    public function test_other_documents_raise_the_odometer_with_their_own_source(): void
    {
        $workshop = $this->makeWorkshop($this->tenant, $this->vehicle->branch);
        $service = app(OdometerService::class);

        $wo = app(WorkOrderService::class)->create($this->vehicle->fresh(), [
            'vehicle_id' => $this->vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM', 'current_odometer' => 10800,
        ]);

        $vehicle = $this->vehicle->fresh();
        $this->assertSame('10800.00', (string) $vehicle->current_odometer);
        $this->assertSame('work_order', $vehicle->odometer_source);
        $this->assertDatabaseHas('vehicle_odometer_readings', ['vehicle_id' => $vehicle->id, 'source' => 'work_order', 'reference_type' => 'work_order', 'reference_id' => $wo->id, 'previous_odometer' => '10000.00']);

        // A lower value from a document changes nothing and leaves no row.
        $this->assertNull($service->raiseFromOperation($vehicle, '9000', OdometerService::SOURCE_INSPECTION, 'inspection', (string) Str::uuid()));
        $this->assertSame(1, VehicleOdometerReading::query()->count());
    }

    public function test_concurrent_looking_writers_never_lose_the_higher_value(): void
    {
        $service = app(OdometerService::class);
        $stale = $this->vehicle->fresh();

        // Two writers loaded the same old row; the second one still decides against the locked, current value.
        $service->raiseFromOperation($stale, '12000', OdometerService::SOURCE_INSPECTION, 'inspection', (string) Str::uuid());
        $this->assertNull($service->raiseFromOperation($stale, '11000', OdometerService::SOURCE_TIRE_OPERATION, 'tire_operation', (string) Str::uuid()));

        $this->assertSame('12000.00', $this->odometer());
        $this->assertSame('inspection', $this->vehicle->fresh()->odometer_source);
    }

    public function test_odometer_math_is_exact_decimal(): void
    {
        $this->assertSame('0.10', OdometerService::normalize('0.1'));
        $this->assertSame('12500.50', OdometerService::normalize(12500.5));
        $this->assertSame('1.01', OdometerService::normalize('1.005'));
        $this->assertSame('99999999.99', OdometerService::normalize('99999999.99'));
        $this->assertNull(OdometerService::normalize('100000000'));
        $this->assertNull(OdometerService::normalize('1e3'));
        $this->assertNull(OdometerService::normalize(-1));
        $this->assertNull(OdometerService::normalize(NAN));
        $this->assertNull(OdometerService::normalize(null));
    }
}
