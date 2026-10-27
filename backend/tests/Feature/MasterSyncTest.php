<?php

namespace Tests\Feature;

use App\Domain\Integration\Master\VehicleMaster;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use App\Domain\Integration\Optinexus\OptinexusEventRelay;
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
}
