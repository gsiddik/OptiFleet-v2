<?php

namespace Tests\Feature;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use App\Domain\Integration\Optinexus\OptinexusEventRelay;
use App\Domain\Vehicle\Services\VehicleAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\Feature\Dashboard\DashboardTestHelpers;
use Tests\Support\FakeNexus;
use Tests\TestCase;

/**
 * Wave 3: OptiDeli hears about vehicle availability through the OptiNexus broker (outbox event) and asks for it before dispatch
 * (GET /api/v1/integration/availability, a tenant-bound credential with its own scope).
 */
class VehicleAvailabilityTest extends TestCase
{
    use DashboardTestHelpers, InteractsWithCentralGovernance;

    private const URL = '/api/v1/integration/availability';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fleet(array $tenantAttributes = []): array
    {
        $tenant = $this->makeTenant($tenantAttributes);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 1234 XY']);

        return [$tenant, $branch, $workshop, $vehicle];
    }

    private function headers($tenant): array
    {
        [, $plain] = AccountingFeedCredential::issue($tenant, 'OptiDeli', null, null, null, AccountingFeedCredential::SCOPE_AVAILABILITY, 'optideli');

        return ['Authorization' => 'Bearer '.$plain];
    }

    private function events(string $tenantId)
    {
        return IntegrationOutboxEvent::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('event_type', VehicleAvailabilityService::EVENT_TYPE)->orderBy('created_at')->get();
    }

    public function test_every_real_change_of_a_linked_tenants_vehicle_is_an_event_and_the_relay_envelope_carries_it(): void
    {
        [$tenant, , , $vehicle] = $this->fleet(['optinexus_tenant_id' => (string) Str::uuid()]);
        $service = app(VehicleAvailabilityService::class);

        $service->change($vehicle, ['status' => 'IN_MAINTENANCE', 'operational_status' => 'ON_HOLD']);
        $service->change($vehicle, ['status' => 'IN_MAINTENANCE']); // no availability change: no event
        $service->change($vehicle->id, ['status' => 'ACTIVE', 'operational_status' => 'AVAILABLE']);
        $service->change($vehicle->id, ['status' => 'ACTIVE', 'operational_status' => 'ON_HOLD']); // back and forth is a new fact

        $events = $this->events($tenant->id);
        $this->assertCount(3, $events);
        $this->assertSame(['ON_HOLD', 'AVAILABLE', 'ON_HOLD'], $events->map(fn ($e) => $e->payload['operational_status'])->all());
        $this->assertSame('AVAILABLE', $events[0]->payload['previous_operational_status']);
        $this->assertSame('B 1234 XY', $events[0]->payload['registration_number']);
        $this->assertSame($vehicle->id, $events[0]->payload['vehicle_id']);

        $envelope = app(OptinexusEventRelay::class)->envelope($events[0]);
        $this->assertSame('optifleet.vehicle.availability_changed', $envelope['event_key']);
        $this->assertSame($tenant->optinexus_tenant_id, $envelope['tenant_id']);
        $this->assertSame('ON_HOLD', $envelope['data']['payload']['operational_status']);
    }

    public function test_a_tenant_not_linked_to_optinexus_records_no_event_but_still_changes_the_vehicle(): void
    {
        [$tenant, , , $vehicle] = $this->fleet();

        app(VehicleAvailabilityService::class)->change($vehicle, ['operational_status' => 'ON_HOLD']);

        $this->assertSame('ON_HOLD', $vehicle->fresh()->operational_status);
        $this->assertCount(0, $this->events($tenant->id));
    }

    public function test_the_query_answers_status_and_scheduled_service_windows(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        [$tenant, $branch, $workshop, $vehicle] = $this->fleet();
        $headers = $this->headers($tenant);

        $this->getJson(self::URL.'?vehicle='.$vehicle->id, $headers)->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.registration_number', 'B 1234 XY')->assertJsonPath('data.blockers', []);

        $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'SCHEDULED', 'target_start_at' => '2026-11-03 06:00:00', 'target_completion_at' => '2026-11-03 18:00:00', 'wo_number' => 'WO-SVC-1']);
        $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'CANCELLED', 'target_start_at' => '2026-11-03 06:00:00', 'target_completion_at' => '2026-11-03 18:00:00']);

        $inside = $this->getJson(self::URL.'?registration_number=b 1234 xy&from=2026-11-03T09:00:00Z&to=2026-11-03T12:00:00Z', $headers)->assertOk();
        $inside->assertJsonPath('data.available', false)->assertJsonPath('data.blockers.0.code', 'SERVICE_SCHEDULED')->assertJsonPath('data.blockers.0.work_order', 'WO-SVC-1');
        $this->assertCount(1, $inside->json('data.blockers'), 'a cancelled work order blocks nothing');

        $this->getJson(self::URL.'?vehicle='.$vehicle->id.'&from=2026-11-04T00:00:00Z&to=2026-11-04T10:00:00Z', $headers)->assertOk()->assertJsonPath('data.available', true);

        $vehicle->update(['operational_status' => 'ON_HOLD', 'status' => 'IN_MAINTENANCE']);
        $this->getJson(self::URL.'?vehicle='.$vehicle->id, $headers)->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonPath('data.blockers.0.code', 'VEHICLE_ON_HOLD');
    }

    public function test_the_tenant_comes_only_from_the_credential_and_the_scope_is_not_interchangeable(): void
    {
        [$tenantA, , , $vehicleA] = $this->fleet();
        [$tenantB] = $this->fleet();

        $this->getJson(self::URL.'?vehicle='.$vehicleA->id, $this->headers($tenantB))->assertNotFound()->assertJsonPath('code', 'VEHICLE_NOT_FOUND');
        $this->getJson(self::URL.'?vehicle='.$vehicleA->id.'&tenant_id='.$tenantA->id, $this->headers($tenantB))->assertNotFound();

        [, $feedToken] = AccountingFeedCredential::issue($tenantA, 'OptiEntry');
        $this->getJson(self::URL.'?vehicle='.$vehicleA->id, ['Authorization' => 'Bearer '.$feedToken])->assertForbidden()->assertJsonPath('code', 'FEED_SCOPE_MISSING');
        $this->getJson('/api/v1/integration/accounting-feed', $this->headers($tenantA))->assertForbidden()->assertJsonPath('code', 'FEED_SCOPE_MISSING');

        $this->getJson(self::URL.'?vehicle='.$vehicleA->id)->assertUnauthorized();
        $this->getJson(self::URL, $this->headers($tenantA))->assertStatus(422);
    }

    public function test_pairing_requires_both_products_but_no_connector_capability(): void
    {
        $this->bootCentralGovernance();
        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, [], 'ACTIVE', [FakeNexus::APP]);
        [$tenant, , , $vehicle] = $this->fleet(['optinexus_tenant_id' => $nexusTenant]);
        $headers = $this->headers($tenant);

        $this->getJson(self::URL.'?vehicle='.$vehicle->id, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_CONSUMER_NOT_ENTITLED');

        $this->nexus->tenant($nexusTenant, [], 'ACTIVE', [FakeNexus::APP, 'optideli']);
        Cache::flush();
        app(CentralGovernance::class)->flushMemo();
        $this->getJson(self::URL.'?vehicle='.$vehicle->id, $headers)->assertOk()->assertJsonPath('data.available', true);
    }

    public function test_a_rotated_availability_credential_stays_an_availability_credential(): void
    {
        [$tenant] = $this->fleet();
        [$old] = AccountingFeedCredential::issue($tenant, 'OptiDeli', null, null, null, AccountingFeedCredential::SCOPE_AVAILABILITY, 'optideli');

        [$new] = AccountingFeedCredential::issue($tenant, 'OptiDeli', null, null, $old);

        $this->assertSame([AccountingFeedCredential::SCOPE_AVAILABILITY], $new->scopes);
        $this->assertSame($old->consumer_application, $new->consumer_application);
    }
}
