<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Governance\CentralGovernance;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationSyncCursor;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Integration\Optinexus\OptinexusSyncService;
use App\Domain\Integration\Optinexus\TelematicsStatus;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeNexus;
use Tests\TestCase;

/**
 * Audit A10: the feed is requested only for tenants that are activated AND subscribed (as OptiNexus says); every run leaves its
 * outcome behind (status, error code, failures, counts); bad items and bad answers never stop or corrupt the feed; and the status the
 * screens read is derived from what was stored, with "not running" never looking like "no data".
 */
class TelematicsSyncStateTest extends TestCase
{
    private Tenant $tenant;

    private Vehicle $vehicle;

    private FakeNexus $nexus;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        app(CentralGovernance::class)->flushMemo();
        $this->nexus = (new FakeNexus)->install();
        config(['optinexus.gateway.feed_page_size' => 10]);

        $this->tenant = $this->linkedTenant(['optifleet', 'optiradar'], activated: true);
        $this->grantModule($this->tenant, 'VEHICLE');
        $this->vehicle = $this->makeVehicle($this->tenant, $this->makeBranch($this->tenant), $this->makeVehicleCategory(), ['current_odometer' => 10000]);
    }

    private function linkedTenant(array $applications, bool $activated, string $status = 'ACTIVE', ?array $subscriptions = null): Tenant
    {
        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, [], $status, $applications, $subscriptions);
        $tenant = $this->makeTenant(['optinexus_tenant_id' => $nexusTenant]);
        if ($activated) {
            $tenant->forceFill(['telematics_activated_at' => now()])->save();
        }

        return $tenant->refresh();
    }

    private function item(string $km, int $minutesAgo, ?string $id = null): array
    {
        return [
            'reading_id' => $id ?? (string) Str::uuid(), 'vehicle_id' => $this->vehicle->id, 'registration_number' => 'X',
            'odometer_km' => $km, 'odometer_kind' => 'DEVICE_ODOMETER', 'recorded_at' => now()->subMinutes($minutesAgo)->toIso8601String(), 'source' => 'optiradar', 'device_ref' => '7',
        ];
    }

    private function cursor(?Tenant $tenant = null): ?IntegrationSyncCursor
    {
        return IntegrationSyncCursor::query()->where('tenant_id', ($tenant ?? $this->tenant)->id)->first();
    }

    private function gatewayCalls(): int
    {
        return $this->nexus->callsTo('/api/gateway/');
    }

    private function token(array $perms = ['telematics_link.view', 'telematics_link.manage'], ?Tenant $tenant = null): string
    {
        return $this->makeTenantUser($tenant ?? $this->tenant, $perms)[1];
    }

    private function statusApi(?string $token = null)
    {
        return $this->getJson('/api/v1/app/telematics/status', $this->authHeaders($token ?? $this->token()));
    }

    // ---------------------------------------------------------------- who is asked

    public function test_a_tenant_that_has_not_activated_the_feed_is_never_asked_anything(): void
    {
        $idle = $this->linkedTenant(['optifleet', 'optiradar'], activated: false);
        $this->tenant->forceFill(['telematics_activated_at' => null])->save();

        $this->artisan('optinexus:sync')->assertSuccessful();

        $this->assertSame(0, $this->gatewayCalls());
        $this->assertSame(0, $this->nexus->callsTo('commercial-context'));
        $this->assertNull($this->cursor($idle));

        // Named explicitly, the reason is printed instead of silence.
        $this->artisan('optinexus:sync', ['--tenant' => $idle->id])->expectsOutputToContain('skipped (not_activated)')->assertSuccessful();
        $this->assertSame(0, $this->gatewayCalls());
    }

    public function test_a_tenant_without_a_live_telematics_subscription_is_skipped_and_the_reason_is_stored(): void
    {
        $cases = [
            'no telematics application' => $this->linkedTenant(['optifleet'], activated: true),
            'no live subscription' => $this->linkedTenant(['optifleet', 'optiradar'], activated: true, subscriptions: []),
            'suspended in OptiNexus' => $this->linkedTenant(['optifleet', 'optiradar'], activated: true, status: 'SUSPENDED'),
        ];
        $this->tenant->forceFill(['telematics_activated_at' => null])->save();

        $this->artisan('optinexus:sync')->assertSuccessful();

        $this->assertSame(0, $this->gatewayCalls(), 'nothing is requested from the gateway for an unsubscribed tenant');
        foreach ($cases as $why => $tenant) {
            $cursor = $this->cursor($tenant);
            $this->assertSame('not_subscribed', $cursor->last_status, $why);
            $this->assertSame(0, $cursor->consecutive_failures, $why);
            $this->assertNull($cursor->last_error, $why);
            $this->assertNotNull($cursor->last_attempted_at, $why);
            $this->assertNull($cursor->last_synced_at, $why);
        }
        $this->grantModule($cases['no telematics application'], 'VEHICLE');
        $token = $this->token(tenant: $cases['no telematics application']);
        $this->statusApi($token)->assertOk()->assertJsonPath('data.integration.state', 'not_subscribed');
    }

    public function test_the_subscription_answer_is_reused_within_the_governance_window(): void
    {
        $this->artisan('optinexus:sync')->assertSuccessful();
        $this->artisan('optinexus:sync')->assertSuccessful();

        $this->assertSame(1, $this->nexus->callsTo('commercial-context'));
    }

    public function test_an_unreachable_optinexus_is_a_failure_that_asks_nothing_and_recovers(): void
    {
        $this->nexus->mode = 'down';

        $this->artisan('optinexus:sync')->assertFailed();
        $this->artisan('optinexus:sync')->assertFailed();

        $cursor = $this->cursor();
        $this->assertSame('provider_unavailable', $cursor->last_status);
        $this->assertSame(2, $cursor->consecutive_failures);
        $this->assertNotNull($cursor->last_error_code);
        $this->assertSame(0, $this->gatewayCalls());
        $this->statusApi()->assertOk()->assertJsonPath('data.integration.state', 'provider_unavailable')->assertJsonPath('data.integration.consecutive_failures', 2);

        $this->nexus->mode = 'up';
        $this->artisan('optinexus:sync')->assertSuccessful();
        $this->assertSame('ok', $this->cursor()->last_status);
        $this->assertSame(0, $this->cursor()->consecutive_failures);
        $this->assertNull($this->cursor()->last_error_code);
    }

    public function test_switching_the_feed_off_stops_all_requests_and_keeps_what_was_read(): void
    {
        $this->nexus->gateway->page([$this->item('10100', 5)], 3);
        $this->artisan('optinexus:sync')->assertSuccessful();
        $calls = $this->gatewayCalls();
        $this->assertSame('10100.00', (string) $this->vehicle->fresh()->current_odometer);

        $this->putJson('/api/v1/app/telematics/activation', ['active' => false], $this->authHeaders($this->token()))->assertOk()->assertJsonPath('data.integration.activated', false);
        $this->artisan('optinexus:sync')->assertSuccessful();

        $this->assertSame($calls, $this->gatewayCalls());
        $this->assertSame(3, (int) $this->cursor()->cursor);
        $this->assertSame(1, VehicleOdometerReading::query()->count());
        $this->statusApi()->assertOk()->assertJsonPath('data.integration.state', 'not_activated');
    }

    // ---------------------------------------------------------------- what a run leaves behind

    public function test_a_run_records_when_it_worked_and_when_a_reading_last_arrived(): void
    {
        $this->nexus->gateway->page([$this->item('10100', 5), $this->item('10150', 4)], 2);
        $this->artisan('optinexus:sync')->assertSuccessful();

        $cursor = $this->cursor();
        $this->assertSame('ok', $cursor->last_status);
        $this->assertNotNull($cursor->last_synced_at);
        $this->assertNotNull($cursor->last_attempted_at);
        $this->assertNotNull($cursor->last_reading_received_at);
        $this->assertSame(2, $cursor->last_stats['received']);
        $this->assertSame(2, $cursor->last_stats['applied']);

        $first = $cursor->last_reading_received_at;
        Carbon::setTestNow(now()->addMinutes(5));
        $this->nexus->gateway->pages = [];
        $this->artisan('optinexus:sync')->assertSuccessful();

        $cursor = $this->cursor()->refresh();
        $this->assertTrue($cursor->last_synced_at->greaterThan($first), 'an empty answer is a successful run');
        $this->assertTrue($cursor->last_reading_received_at->equalTo($first), 'but no reading arrived, and that is not hidden');
        Carbon::setTestNow();
    }

    public function test_readings_keep_measured_and_received_time_source_and_unit_apart(): void
    {
        $this->nexus->gateway->page([$this->item('10100', 90)], 1);
        $this->artisan('optinexus:sync')->assertSuccessful();

        $reading = VehicleOdometerReading::query()->firstOrFail();
        $this->assertTrue($reading->recorded_at->lessThan(now()->subMinutes(80)), 'measured_at is the device time');
        $this->assertTrue($reading->received_at->greaterThan(now()->subMinutes(2)), 'received_at is when OptiFleet stored it');
        $this->assertSame('km', $reading->unit);
        $this->assertSame('optinexus', $reading->source);
        $this->assertSame('optiradar', $reading->origin);
        $this->assertSame('optinexus', $this->vehicle->fresh()->odometer_source);
        $this->assertTrue($this->vehicle->fresh()->odometer_measured_at->equalTo($reading->recorded_at));
    }

    public function test_unusable_items_are_counted_and_skipped_never_zero_filled_and_the_feed_goes_on(): void
    {
        $noOdometer = $this->item('1', 3);
        unset($noOdometer['odometer_km']);
        $badVehicle = $this->item('10200', 2) + [];
        $badVehicle['vehicle_id'] = 'not-a-uuid';
        $this->nexus->gateway->page([
            $this->item('10100', 5), $noOdometer, $badVehicle, 'a string, not an item', $this->item('-4', 1), $this->item('10120', 1),
        ], 6);

        $this->artisan('optinexus:sync')->assertSuccessful();

        $cursor = $this->cursor();
        $this->assertSame(6, (int) $cursor->cursor, 'the cursor moves past bad items');
        $this->assertSame('partial', $cursor->last_status);
        $this->assertSame(2, $cursor->last_stats['applied']);
        $this->assertSame(4, $cursor->last_stats['rejected']);
        $this->assertCount(4, $cursor->last_stats['rejected_sample']);
        $this->assertContains('INVALID_ODOMETER', array_column($cursor->last_stats['rejected_sample'], 'reason'));
        $this->assertContains('INVALID_VEHICLE_ID', array_column($cursor->last_stats['rejected_sample'], 'reason'));
        $this->assertSame(2, VehicleOdometerReading::query()->count());
        $this->assertSame('10120.00', (string) $this->vehicle->fresh()->current_odometer);
        $this->assertSame(0, VehicleOdometerReading::query()->where('reported_km', '<=', 0)->count());
        $this->statusApi()->assertOk()->assertJsonPath('data.integration.state', 'partial');
    }

    public function test_an_answer_the_feed_cannot_use_changes_nothing_and_is_reported(): void
    {
        $this->nexus->gateway->rawFeedAnswer = ['items' => 'nope', 'next_cursor' => '5'];
        $this->artisan('optinexus:sync')->assertFailed();
        $this->assertSame(0, (int) $this->cursor()->cursor);
        $this->assertSame('GATEWAY_BAD_RESPONSE', $this->cursor()->last_error_code);

        $this->cursor()->forceFill(['cursor' => 10])->save();
        $this->nexus->gateway->rawFeedAnswer = ['items' => [$this->item('10100', 5)], 'next_cursor' => '3']; // goes backwards
        $this->artisan('optinexus:sync')->assertFailed();
        $this->assertSame(10, (int) $this->cursor()->cursor);
        $this->assertSame(0, VehicleOdometerReading::query()->count(), 'a page that cannot be trusted is not applied');
        $this->assertSame(2, $this->cursor()->consecutive_failures);
    }

    public function test_a_gateway_failure_is_classified_counted_and_cleared_by_the_next_good_run(): void
    {
        $this->nexus->gateway->down = true;
        $this->artisan('optinexus:sync')->assertFailed();
        $this->assertSame('CONNECTION_FAILED', $this->cursor()->last_error_code);

        $this->nexus->gateway->down = false;
        $this->nexus->gateway->fail('RATE_LIMITED', 429);
        $this->artisan('optinexus:sync')->assertFailed();
        $this->assertSame('RATE_LIMITED', $this->cursor()->last_error_code);
        $this->assertSame(2, $this->cursor()->consecutive_failures);
        $this->statusApi()->assertOk()->assertJsonPath('data.integration.state', 'error')->assertJsonPath('data.integration.last_error.code', 'RATE_LIMITED');

        $this->nexus->gateway->error = null;
        $this->artisan('optinexus:sync')->assertSuccessful();
        $this->assertSame(0, $this->cursor()->consecutive_failures);
        $this->assertNull($this->cursor()->last_error_code);
    }

    public function test_one_tenant_failing_does_not_stop_the_others(): void
    {
        $second = $this->linkedTenant(['optifleet', 'optiradar'], activated: true);
        $vehicle = $this->makeVehicle($second, $this->makeBranch($second), $this->makeVehicleCategory(), ['current_odometer' => 500]);
        $this->nexus->gateway->page([$this->item('10100', 5)], 1);

        $this->artisan('optinexus:sync')->assertSuccessful();

        $this->assertSame('ok', $this->cursor($second)->last_status);
        $this->assertSame('ok', $this->cursor()->last_status);
        $this->assertSame('500.00', (string) $vehicle->fresh()->current_odometer, 'a reading for another tenant\'s vehicle is unknown here and touches nothing');
    }

    // ---------------------------------------------------------------- the status the screens read

    public function test_the_state_follows_the_facts_in_a_fixed_order(): void
    {
        $now = CarbonImmutable::parse('2026-10-17T10:00:00Z');
        $recent = $now->subMinutes(2);
        $old = $now->subHours(2);
        $state = fn (bool $enabled, bool $linked, bool $activated, ?string $status, $attempted, $synced) => TelematicsStatus::state($enabled, $linked, $activated, $status, $attempted, $synced, 15, $now);

        $this->assertSame('disabled', $state(false, true, true, 'ok', $recent, $recent));
        $this->assertSame('not_linked', $state(true, false, true, 'ok', $recent, $recent));
        $this->assertSame('not_activated', $state(true, true, false, 'ok', $recent, $recent));
        $this->assertSame('pending', $state(true, true, true, null, null, null));
        $this->assertSame('not_subscribed', $state(true, true, true, 'not_subscribed', $recent, null));
        $this->assertSame('provider_unavailable', $state(true, true, true, 'provider_unavailable', $recent, $old));
        $this->assertSame('error', $state(true, true, true, 'error', $recent, $recent));
        $this->assertSame('stale', $state(true, true, true, 'ok', $old, $old), 'worked once, but not for two hours: the schedule is not running');
        $this->assertSame('partial', $state(true, true, true, 'partial', $recent, $recent));
        $this->assertSame('ok', $state(true, true, true, 'ok', $recent, $recent));
    }

    public function test_a_silent_device_is_labelled_stale_but_never_hidden_or_zeroed(): void
    {
        $now = CarbonImmutable::parse('2026-10-17T10:00:00Z');

        $this->assertNull(TelematicsStatus::readingAgeSeconds(null, $now), 'no reading is no age, not zero');
        $this->assertFalse(TelematicsStatus::readingIsStale(null, 24));
        $this->assertSame(0, TelematicsStatus::readingAgeSeconds($now->addMinutes(3), $now), 'a clock ahead never gives a negative age');
        $this->assertTrue(TelematicsStatus::readingIsStale(TelematicsStatus::readingAgeSeconds($now->subHours(25), $now), 24));
        $this->assertFalse(TelematicsStatus::readingIsStale(TelematicsStatus::readingAgeSeconds($now->subHours(23), $now), 24));
    }

    public function test_the_status_counts_devices_by_what_they_have_actually_reported(): void
    {
        $this->nexus->gateway->page([$this->item('10100', 5)], 1);
        $this->artisan('optinexus:sync')->assertSuccessful();
        $silent = VehicleTelematicsLink::query()->create(['tenant_id' => $this->tenant->id, 'vehicle_id' => $this->vehicle->id, 'source' => 'optinexus', 'device_ref' => '99']);
        $old = $this->makeVehicle($this->tenant, $this->vehicle->branch, $this->vehicle->vehicleCategory);
        VehicleTelematicsLink::query()->create([
            'tenant_id' => $this->tenant->id, 'vehicle_id' => $old->id, 'source' => 'optinexus', 'device_ref' => '55', 'last_measured_at' => now()->subDays(3),
        ]);

        $r = $this->statusApi()->assertOk();

        $r->assertJsonPath('data.integration.state', 'ok')->assertJsonPath('data.integration.activated', true)
            ->assertJsonPath('data.devices.total', 3)->assertJsonPath('data.devices.with_readings', 2)->assertJsonPath('data.devices.without_readings', 1)
            ->assertJsonPath('data.devices.stale', 1)->assertJsonPath('data.devices.stale_after_hours', 24);
        $list = $this->getJson('/api/v1/app/telematics-links?per_page=50', $this->authHeaders($this->token()))->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $silent->id);
        $this->assertFalse($row['has_readings']);
        $this->assertNull($row['last_measured_at']);
        $this->assertNull($row['age_seconds']);
        $this->assertNull($row['latest_reading']);
        $this->assertFalse($row['stale']);
    }

    public function test_status_without_optinexus_says_so_and_the_manual_odometer_still_works(): void
    {
        config(['optinexus.enabled' => false]);
        Http::fake();

        $this->statusApi()->assertOk()->assertJsonPath('data.integration.state', 'disabled')->assertJsonPath('data.integration.enabled', false);
        $this->putJson('/api/v1/app/telematics/activation', ['active' => true], $this->authHeaders($this->token()))->assertStatus(409)->assertJsonPath('code', 'TELEMATICS_UNAVAILABLE');
        $this->postJson('/api/v1/app/vehicles/'.$this->vehicle->id.'/odometer-readings', ['odometer_km' => 10500], $this->authHeaders($this->makeTenantUser($this->tenant, ['vehicle.view', 'vehicle.update'])[1]))
            ->assertStatus(201)->assertJsonPath('data.current.value', '10500.00');
        Http::assertNothingSent();
    }

    public function test_an_unlinked_tenant_cannot_switch_the_feed_on(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'VEHICLE');
        $token = $this->token(tenant: $tenant);

        $this->statusApi($token)->assertOk()->assertJsonPath('data.integration.state', 'not_linked');
        $this->putJson('/api/v1/app/telematics/activation', ['active' => true], $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'TELEMATICS_NOT_LINKED');
        $this->assertNull($tenant->fresh()->telematics_activated_at);
    }

    public function test_activation_is_idempotent_audited_permissioned_and_tenant_bound(): void
    {
        $tenant = $this->linkedTenant(['optifleet', 'optiradar'], activated: false);
        $this->grantModule($tenant, 'VEHICLE');
        $token = $this->token(tenant: $tenant);
        $headers = $this->authHeaders($token);

        $this->putJson('/api/v1/app/telematics/activation', ['active' => 'maybe'], $headers)->assertStatus(422);
        $this->putJson('/api/v1/app/telematics/activation', ['active' => true], $this->authHeaders($this->token(['telematics_link.view'], $tenant)))->assertStatus(403);

        $this->putJson('/api/v1/app/telematics/activation', ['active' => true], $headers)->assertOk()
            ->assertJsonPath('data.integration.activated', true)->assertJsonPath('data.integration.state', 'pending');
        $first = $tenant->fresh()->telematics_activated_at;
        $this->putJson('/api/v1/app/telematics/activation', ['active' => true], $headers)->assertOk();
        $this->assertTrue($tenant->fresh()->telematics_activated_at->equalTo($first), 'switching on twice changes nothing');
        $this->assertSame(1, AuditLog::query()->where('action', 'telematics.activated')->where('resource_id', $tenant->id)->count());
        $this->assertNotNull($tenant->fresh()->telematics_activated_by);

        $this->putJson('/api/v1/app/telematics/activation', ['active' => false], $headers)->assertOk()->assertJsonPath('data.integration.activated', false);
        $this->assertTrue(AuditLog::query()->where('action', 'telematics.deactivated')->where('resource_id', $tenant->id)->exists());

        // The first tenant's own switch is untouched by all of this.
        $this->assertNotNull($this->tenant->fresh()->telematics_activated_at);
    }

    public function test_the_status_needs_the_view_permission_and_the_vehicle_module(): void
    {
        $this->statusApi($this->token(['vehicle.view']))->assertStatus(403);

        $noModule = $this->makeTenant();
        $this->statusApi($this->token(['telematics_link.view'], $noModule))->assertStatus(403);
    }

    public function test_the_sync_service_can_be_called_for_a_named_state_without_throwing(): void
    {
        $this->tenant->forceFill(['telematics_activated_at' => null])->save();

        $r = app(OptinexusSyncService::class)->syncTenant($this->tenant->refresh());

        $this->assertSame('not_activated', $r['state']);
        $this->assertSame(0, $r['received']);
        $this->assertSame('not_activated', $this->cursor()->last_status);
    }
}
