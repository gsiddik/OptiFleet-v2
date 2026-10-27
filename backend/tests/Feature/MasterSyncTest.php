<?php

namespace Tests\Feature;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Integration\Master\VehicleDrivers;
use App\Domain\Integration\Master\VehicleMaster;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use App\Domain\Integration\Optinexus\OptinexusEventRelay;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Vehicles are keyed once: OptiFleet's asset data goes out, OptiDeli's transport data comes in as a draft or a side profile. */
class MasterSyncTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const URL = '/api/v1/integration/optinexus/events';

    protected function setUp(): void
    {
        parent::setUp();
        config(['optinexus.events.webhook_secret' => self::SECRET]);
    }

    private function tenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'MS-'.Str::random(4), 'optinexus_tenant_id' => (string) Str::uuid()]);
        $this->grantModule($tenant, 'ORGANIZATION');
        $this->grantModule($tenant, 'VEHICLE');

        return [$tenant, $this->makeBranch($tenant), $this->makeVehicleCategory()];
    }

    private function event(string $nexusTenant, int $version, string $plate = 'b-1234-xy', array $transport = ['carrier_type_code' => 'CDD', 'capacity_g' => 2500000, 'length_mm' => 4200, 'width_mm' => 2000, 'height_mm' => 2000]): array
    {
        return [
            'event_id' => (string) Str::uuid(), 'event_type' => 'optideli.vehicle.upserted', 'schema_version' => '1', 'source_system' => 'optideli',
            'tenant_id' => $nexusTenant, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'vehicle', 'aggregate_id' => (string) Str::uuid(), 'correlation' => [], 'payload' => [
                'key' => 'B1234XY', 'registration_number' => $plate, 'version' => $version, 'changed_at' => now()->toIso8601String(), 'transport' => $transport,
            ]],
        ];
    }

    private function deliver(array $event, ?string $secret = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $ts = (string) time();

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_OPTI_WEBHOOK_ID' => $id, 'HTTP_OPTI_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_OPTI_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $id.'.'.$ts.'.'.$body, $secret ?? self::SECRET),
        ], $body);
    }

    private function published(string $tenantId)
    {
        return IntegrationOutboxEvent::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('event_type', VehicleMaster::EVENT_TYPE)->orderBy('created_at')->get();
    }

    public function test_an_unknown_vehicle_from_optideli_becomes_a_draft_with_its_transport_profile_and_publishes_nothing(): void
    {
        [$tenant] = $this->tenant();

        $this->deliver($this->event($tenant->optinexus_tenant_id, 1))->assertStatus(202)->assertJson(['result' => 'created']);

        $v = DB::table('vehicles')->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(['b-1234-xy', 'DRAFT', 'OPTIDELI', null, null], [$v->registration_number, $v->status, $v->source_application, $v->branch_id, $v->brand]);
        $profile = DB::table('vehicle_transport_profiles')->where('vehicle_id', $v->id)->sole();
        $this->assertSame(['CDD', 2500000, 4200], [$profile->carrier_type_code, $profile->capacity_g, $profile->length_mm]);
        $this->assertCount(0, $this->published($tenant->id), 'what came from another platform is not sent back');
    }

    public function test_a_known_vehicle_keeps_its_asset_data_and_only_the_profile_changes_and_old_versions_are_ignored(): void
    {
        [$tenant, $branch, $category] = $this->tenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1234 XY', 'brand' => 'Hino', 'model' => 'Dutro']);

        $this->deliver($this->event($tenant->optinexus_tenant_id, 10))->assertJson(['result' => 'applied']);
        $this->deliver($this->event($tenant->optinexus_tenant_id, 9, transport: ['capacity_g' => 1]))->assertJson(['result' => 'stale']);

        $this->assertSame(['Hino', 'Dutro', 'ACTIVE'], [$vehicle->fresh()->brand, $vehicle->fresh()->model, $vehicle->fresh()->status]);
        $this->assertSame(2500000, (int) DB::table('vehicle_transport_profiles')->where('vehicle_id', $vehicle->id)->value('capacity_g'));
        $this->assertSame(1, DB::table('vehicles')->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_signature_the_producer_and_the_tenant_are_checked(): void
    {
        [$tenant] = $this->tenant();
        $event = $this->event($tenant->optinexus_tenant_id, 1);

        $this->deliver($event, 'wrong-secret')->assertStatus(401);
        config(['optinexus.events.webhook_secret' => '']);
        $this->deliver($event)->assertStatus(401);
        config(['optinexus.events.webhook_secret' => self::SECRET]);

        $this->deliver(['source_system' => 'optiradar'] + $event)->assertJson(['result' => 'ignored']);
        $this->deliver(['tenant_id' => (string) Str::uuid()] + $event)->assertJson(['result' => 'ignored']);
        $this->assertSame(0, DB::table('vehicles')->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_tenants_vehicle_is_published_with_its_asset_data_when_created_and_when_changed(): void
    {
        [$tenant, $branch, $category] = $this->tenant();
        $brand = $this->makeVehicleBrand();
        $model = $this->makeVehicleModel($brand);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.create', 'vehicle.update']);

        $id = $this->postJson('/api/v1/app/vehicles', [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id, 'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'registration_number' => 'B 9 ZZ', 'vin' => 'MHF123', 'year' => 2021,
        ], $this->authHeaders($token))->assertCreated()->json('data.id');
        $this->putJson("/api/v1/app/vehicles/{$id}", ['fuel_type' => 'Diesel'], $this->authHeaders($token))->assertOk();

        $events = $this->published($tenant->id);
        $this->assertCount(2, $events);
        $payload = $events[0]->payload;
        $this->assertSame(['B9ZZ', 'MHF123', 2021, $brand->name], [$payload['key'], $payload['asset']['vin'], $payload['asset']['year'], $payload['asset']['brand']]);
        $this->assertArrayNotHasKey('transport', $payload);
        $this->assertSame('Diesel', $events[1]->payload['asset']['fuel_type']);
        $this->assertGreaterThanOrEqual($events[0]->payload['version'], $events[1]->payload['version']);

        $envelope = app(OptinexusEventRelay::class)->envelope($events[0]);
        $this->assertSame('optifleet.vehicle.upserted', $envelope['event_key']);
        $this->assertSame($tenant->optinexus_tenant_id, $envelope['tenant_id']);
    }

    public function test_a_draft_is_completed_by_staff_then_counts_and_is_published(): void
    {
        [$tenant, $branch, $category] = $this->tenant();
        $brand = $this->makeVehicleBrand();
        $model = $this->makeVehicleModel($brand);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.create', 'vehicle.update']);
        $this->deliver($this->event($tenant->optinexus_tenant_id, 1));
        $draft = DB::table('vehicles')->where('tenant_id', $tenant->id)->sole();

        $this->getJson("/api/v1/app/vehicles/{$draft->id}", $this->authHeaders($token))->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.transport_profile.carrier_type_code', 'CDD');
        $this->postJson("/api/v1/app/vehicles/{$draft->id}/complete", ['branch_id' => $branch->id], $this->authHeaders($token))->assertStatus(422);
        $this->postJson("/api/v1/app/vehicles/{$draft->id}/complete", [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id, 'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
        ], $this->authHeaders($token))->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.brand', $brand->name);

        $this->assertCount(1, $this->published($tenant->id));
        $this->postJson("/api/v1/app/vehicles/{$draft->id}/complete", [
            'branch_id' => $branch->id, 'vehicle_category_id' => $category->id, 'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_the_database_refuses_an_incomplete_vehicle_that_is_not_a_draft(): void
    {
        [$tenant] = $this->tenant();

        $this->expectException(QueryException::class);
        DB::table('vehicles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'registration_number' => 'B 1 A', 'status' => 'ACTIVE', 'operational_status' => 'AVAILABLE', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_backfill_publishes_complete_vehicles_of_linked_tenants_only(): void
    {
        [$linked, $branch, $category] = $this->tenant();
        $this->makeVehicle($linked, $branch, $category);
        $this->deliver($this->event($linked->optinexus_tenant_id, 1, 'B 5 DRAFT'));
        $other = $this->makeTenant(['code' => 'MS-'.Str::random(4)]);
        $this->makeVehicle($other, $this->makeBranch($other), $category);

        $this->assertSame(1, app(VehicleMaster::class)->publishAll());
        $this->assertCount(1, $this->published($linked->id));
        $this->assertCount(0, $this->published($other->id));
    }

    public function test_a_draft_is_outside_ordinary_queries_and_cannot_be_given_a_status(): void
    {
        [$tenant, $branch, $category] = $this->tenant();
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.update', 'vehicle.status.update']);
        $this->makeVehicle($tenant, $branch, $category);
        $this->deliver($this->event($tenant->optinexus_tenant_id, 1, 'B 5 DRAFT'));
        $draft = DB::table('vehicles')->where('tenant_id', $tenant->id)->where('status', 'DRAFT')->sole();

        $this->assertSame(1, Vehicle::query()->where('tenant_id', $tenant->id)->count());
        $this->postJson("/api/v1/app/vehicles/{$draft->id}/status", ['status' => 'ACTIVE'], $this->authHeaders($token))->assertNotFound();
        $this->putJson("/api/v1/app/vehicles/{$draft->id}", ['fuel_type' => 'Diesel'], $this->authHeaders($token))->assertNotFound();
        $this->getJson('/api/v1/app/vehicles?status=DRAFT', $this->authHeaders($token))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_plate_the_tenant_deleted_is_not_revived_by_an_event(): void
    {
        [$tenant, $branch, $category] = $this->tenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1234 XY']);
        $vehicle->delete();

        $this->deliver($this->event($tenant->optinexus_tenant_id, 1))->assertStatus(202)->assertJson(['result' => 'ignored']);
        $this->assertSame(0, DB::table('vehicle_transport_profiles')->where('tenant_id', $tenant->id)->count());
    }

    private function radarEvent(string $nexusTenant, int $version, string $plate = 'b-1234-xy', array $telematics = ['device_ref' => 'IMEI10', 'device_name' => 'Truck 10']): array
    {
        return [
            'event_id' => (string) Str::uuid(), 'event_type' => 'optiradar.vehicle.upserted', 'schema_version' => '1', 'source_system' => 'optiradar',
            'tenant_id' => $nexusTenant, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'vehicle', 'aggregate_id' => '10', 'correlation' => [], 'payload' => [
                'key' => 'B1234XY', 'registration_number' => $plate, 'version' => $version, 'changed_at' => now()->toIso8601String(), 'telematics' => $telematics,
            ]],
        ];
    }

    public function test_a_device_of_optiradar_creates_a_draft_or_attaches_to_the_vehicle_and_never_touches_the_asset(): void
    {
        [$tenant, $branch, $category] = $this->tenant();

        $this->deliver($this->radarEvent($tenant->optinexus_tenant_id, 1, 'D 8101 AH'))->assertStatus(202)->assertJson(['result' => 'created']);
        $draft = DB::table('vehicles')->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(['DRAFT', 'OPTIRADAR', null], [$draft->status, $draft->source_application, $draft->brand]);
        $this->assertSame('IMEI10', DB::table('vehicle_telematics_profiles')->where('vehicle_id', $draft->id)->value('device_ref'));
        $this->assertCount(0, $this->published($tenant->id), 'what came from another platform is not sent back');

        $known = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1234 XY', 'brand' => 'Hino', 'model' => 'Dutro']);
        $this->deliver($this->radarEvent($tenant->optinexus_tenant_id, 2))->assertJson(['result' => 'applied']);
        $this->deliver($this->radarEvent($tenant->optinexus_tenant_id, 1, telematics: ['device_ref' => 'OLD']))->assertJson(['result' => 'stale']);
        $this->assertSame(['Hino', 'Dutro', 'ACTIVE'], [$known->fresh()->brand, $known->fresh()->model, $known->fresh()->status]);
        $this->assertSame('IMEI10', DB::table('vehicle_telematics_profiles')->where('vehicle_id', $known->id)->value('device_ref'));
    }

    public function test_the_producer_of_a_group_must_be_its_owner(): void
    {
        [$tenant] = $this->tenant();
        $forged = $this->radarEvent($tenant->optinexus_tenant_id, 1);
        $forged['source_system'] = 'optideli'; // OptiDeli claiming the telematics group of OptiRadar

        $this->deliver($forged)->assertJson(['result' => 'ignored']);
        $this->assertSame(0, DB::table('vehicles')->where('tenant_id', $tenant->id)->count());
    }

    private function driverEvent(string $nexusTenant, string $at, string $name, string $referenceId, string $plate = 'B 1234 XY', string $type = 'SPM_TRIP'): array
    {
        return [
            'event_id' => (string) Str::uuid(), 'event_type' => 'optideli.vehicle.driver_assigned', 'schema_version' => '1', 'source_system' => 'optideli',
            'tenant_id' => $nexusTenant, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'vehicle', 'aggregate_id' => (string) Str::uuid(), 'correlation' => [], 'payload' => [
                'key' => 'B1234XY', 'registration_number' => $plate, 'assigned_at' => $at,
                'reference' => ['type' => $type, 'id' => $referenceId, 'number' => 'PJ-1'],
                'driver' => ['name' => $name, 'phone' => '0812', 'license_masked' => '****4567'],
            ]],
        ];
    }

    public function test_driver_facts_become_periods_per_vehicle_in_any_order_and_twice_and_without_a_driver_register(): void
    {
        [$tenant, $branch, $category] = $this->tenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1234 XY']);
        $nexus = $tenant->optinexus_tenant_id;
        $facts = [
            ['2026-10-01T08:00:00Z', 'Budi', (string) Str::uuid()], ['2026-10-02T08:00:00Z', 'Budi', (string) Str::uuid()],
            ['2026-10-03T08:00:00Z', 'Andi', (string) Str::uuid()], ['2026-10-04T08:00:00Z', 'Budi', (string) Str::uuid()],
        ];
        foreach (array_reverse($facts) as $f) { // newest first: facts are unordered
            $this->deliver($this->driverEvent($nexus, $f[0], $f[1], $f[2]))->assertStatus(202)->assertJson(['result' => 'applied']);
        }
        $this->deliver($this->driverEvent($nexus, $facts[0][0], 'Budi', $facts[0][2]))->assertJson(['result' => 'applied']); // a repeat

        $this->assertSame(4, DB::table('vehicle_driver_assignments')->where('tenant_id', $tenant->id)->count());
        $periods = app(VehicleDrivers::class)->history($vehicle);
        $this->assertSame(['Budi', 'Andi', 'Budi'], array_column($periods, 'driver_name'));
        $this->assertSame([1, 1, 2], array_column($periods, 'assignments'));
        $this->assertSame('2026-10-01T08:00:00Z', $periods[2]['first_assigned_at']);
        $this->assertSame('Andi', app(VehicleDrivers::class)->lastBefore($vehicle, CarbonImmutable::parse('2026-10-03T12:00:00Z'))['driver_name']);
        $this->assertNull(app(VehicleDrivers::class)->lastBefore($vehicle, CarbonImmutable::parse('2026-09-01T00:00:00Z')));
        $this->assertCount(0, $this->published($tenant->id));
        $this->assertSame(0, DB::table('vehicles')->where('tenant_id', $tenant->id)->count() - 1, 'a driver fact never creates a vehicle');
    }

    public function test_a_driver_fact_needs_the_producer_a_valid_reference_and_a_name(): void
    {
        [$tenant] = $this->tenant();
        $nexus = $tenant->optinexus_tenant_id;
        $forged = $this->driverEvent($nexus, '2026-10-01T08:00:00Z', 'Budi', (string) Str::uuid());
        $forged['source_system'] = 'optiradar';

        $this->deliver($forged)->assertJson(['result' => 'ignored']);
        $this->deliver($this->driverEvent($nexus, 'not a date', 'Budi', (string) Str::uuid()))->assertJson(['result' => 'ignored']);
        $this->deliver($this->driverEvent($nexus, '2026-10-01T08:00:00Z', '  ', (string) Str::uuid()))->assertJson(['result' => 'ignored']);
        $this->deliver($this->driverEvent($nexus, '2026-10-01T08:00:00Z', 'Budi', 'not-a-uuid'))->assertJson(['result' => 'ignored']);
        $this->deliver($this->driverEvent($nexus, '2026-10-01T08:00:00Z', 'Budi', (string) Str::uuid(), type: 'OTHER'))->assertJson(['result' => 'ignored']);
        $this->assertSame(0, DB::table('vehicle_driver_assignments')->count());
    }

    public function test_the_drivers_tab_exists_only_for_a_tenant_that_bought_optideli_and_the_work_order_shows_the_last_driver(): void
    {
        [$tenant, $branch, $category] = $this->tenant();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1234 XY']);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $this->deliver($this->driverEvent($tenant->optinexus_tenant_id, '2026-10-01T08:00:00Z', 'Budi', (string) Str::uuid()));
        $headers = $this->authHeaders($token);

        // What OptiNexus says the tenant bought. A controller instance lives as long as the app of the test, so the answer is switched here.
        $apps = ['optifleet'];
        $this->partialMock(CentralGovernance::class, function ($mock) use (&$apps) {
            $mock->shouldReceive('commercial')->andReturnUsing(function () use (&$apps) {
                return ['applications' => $apps];
            });
        });
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}", $headers)->assertOk()->assertJsonPath('data.driver_history_available', false);
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/drivers", $headers)->assertNotFound();

        $apps = ['optifleet', 'optideli'];
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}", $headers)->assertOk()->assertJsonPath('data.driver_history_available', true);
        $rows = $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/drivers", $headers)->assertOk()->json('data');
        $this->assertSame([['Budi', '****4567', 1]], array_map(fn ($r) => [$r['driver_name'], $r['license_masked'], $r['assignments']], $rows));
    }
}
