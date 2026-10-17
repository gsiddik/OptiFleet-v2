<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Integration\Optinexus\VehicleOdometerService;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit A10: readings that cannot be trusted (counter reset, device replacement, impossible jump, device clock in the future, late
 * data) are quarantined and reviewed by a person; they never apply, never lower the odometer, and never block the feed.
 */
class TelematicsAnomalyTest extends TestCase
{
    private Tenant $tenant;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->grantModule($this->tenant, 'VEHICLE');
        $this->vehicle = $this->makeVehicle($this->tenant, $this->makeBranch($this->tenant), $this->makeVehicleCategory(), ['current_odometer' => 10000]);
    }

    private function t(int $hours): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-01T00:00:00Z')->addHours($hours);
    }

    /** Records one reading as the feed would deliver it; $received is the instant it arrives (default: long after every test time). */
    private function rec(string $km, int $hours, string $kind = 'DEVICE_ODOMETER', string $device = '7', ?CarbonImmutable $received = null, ?CarbonImmutable $at = null): string
    {
        return app(VehicleOdometerService::class)->record($this->tenant->id, [
            'reading_id' => (string) Str::uuid(), 'vehicle_id' => $this->vehicle->id, 'odometer_km' => $km, 'odometer_kind' => $kind,
            'recorded_at' => ($at ?? $this->t($hours))->toIso8601String(), 'source' => 'optiradar', 'device_ref' => $device,
        ], $received ?? $this->t(1000));
    }

    private function odometer(): string
    {
        return (string) $this->vehicle->fresh()->current_odometer;
    }

    private function link(string $device = '7'): VehicleTelematicsLink
    {
        return VehicleTelematicsLink::query()->where('device_ref', $device)->firstOrFail();
    }

    private function token(array $perms = ['telematics_link.view', 'telematics_link.manage'], ?Tenant $tenant = null, ?array $scopes = null): string
    {
        return $this->makeTenantUser($tenant ?? $this->tenant, $perms, $scopes)[1];
    }

    private function review(string $decision, string $note = 'Checked with the workshop on site', array $extra = [], ?string $token = null, string $device = '7')
    {
        return $this->postJson('/api/v1/app/telematics-links/'.$this->link($device)->id.'/review', ['decision' => $decision, 'note' => $note] + $extra, $this->authHeaders($token ?? $this->token()));
    }

    // ---------------------------------------------------------------- detection

    public function test_a_falling_device_counter_is_quarantined_and_puts_the_device_under_review(): void
    {
        $this->assertSame('applied', $this->rec('12000', 1));
        $this->assertSame('quarantined', $this->rec('11000', 2));

        $this->assertSame('12000.00', $this->odometer());
        $link = $this->link();
        $this->assertTrue($link->underReview());
        $this->assertSame('COUNTER_DECREASE', $link->review_reason);
        $this->assertDatabaseHas('vehicle_odometer_readings', [
            'id' => $link->review_reading_id, 'disposition' => 'quarantined', 'anomaly_code' => 'COUNTER_DECREASE', 'review_status' => 'pending',
            'effective_km' => null, 'applied' => false, 'previous_odometer' => '12000.00',
        ]);

        // While it is under review nothing from this device applies, not even a value above the odometer.
        $this->assertSame('quarantined', $this->rec('13000', 3));
        $this->assertSame('12000.00', $this->odometer());
        $this->assertSame(1, VehicleOdometerReading::query()->where('review_status', 'pending')->count(), 'one open review per device, not one per reading');
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '13000.00', 'disposition' => 'quarantined', 'anomaly_code' => 'COUNTER_DECREASE', 'review_status' => null]);
    }

    public function test_jitter_within_the_tolerance_is_not_an_anomaly(): void
    {
        $this->rec('12000', 1);

        $this->assertSame('stored', $this->rec('11999.50', 2));
        $this->assertFalse($this->link()->underReview());
    }

    public function test_a_jump_no_vehicle_could_drive_is_quarantined_but_a_long_silence_is_not(): void
    {
        $this->rec('12000', 1);
        $this->assertSame('quarantined', $this->rec('17000', 2)); // 5000 km in one hour
        $this->assertSame('IMPLAUSIBLE_JUMP', $this->link()->review_reason);
        $this->assertSame('12000.00', $this->odometer());

        $other = $this->makeVehicle($this->tenant, $this->vehicle->branch, $this->vehicle->vehicleCategory, ['current_odometer' => 100]);
        $svc = app(VehicleOdometerService::class);
        $this->assertSame('applied', $svc->record($this->tenant->id, ['reading_id' => (string) Str::uuid(), 'vehicle_id' => $other->id, 'odometer_km' => '2000', 'recorded_at' => $this->t(1)->toIso8601String(), 'device_ref' => '9'], $this->t(1000)));
        // Ten days later the same device reports 4,000 km more: a vehicle can drive that in ten days.
        $this->assertSame('applied', $svc->record($this->tenant->id, ['reading_id' => (string) Str::uuid(), 'vehicle_id' => $other->id, 'odometer_km' => '6000', 'recorded_at' => $this->t(241)->toIso8601String(), 'device_ref' => '9'], $this->t(1000)));
        $this->assertSame('6000.00', (string) $other->fresh()->current_odometer);
    }

    public function test_a_second_device_on_a_vehicle_that_had_one_is_a_device_change_for_review(): void
    {
        $this->assertSame('applied', $this->rec('12000', 1, device: '7'));

        $this->assertSame('quarantined', $this->rec('100', 2, device: '8'));

        $this->assertSame('12000.00', $this->odometer());
        $this->assertSame('DEVICE_CHANGED', $this->link('8')->review_reason);
        $this->assertFalse($this->link('7')->underReview());
    }

    public function test_the_first_device_of_a_vehicle_is_applied_as_before_and_a_gps_device_waits_for_calibration(): void
    {
        $this->assertSame('applied', $this->rec('12000', 1, device: '7'));
        $this->assertSame('held', $this->rec('300', 2, 'GPS_DISTANCE', device: '8'));

        $this->assertFalse($this->link('8')->underReview());
    }

    public function test_a_device_clock_in_the_future_is_quarantined_without_blocking_later_readings(): void
    {
        $received = $this->t(10);

        $this->assertSame('quarantined', $this->rec('12000', 0, received: $received, at: $received->addHours(3)));
        $link = $this->link();
        $this->assertFalse($link->underReview(), 'a clock fault needs no review');
        $this->assertNull($link->last_measured_at, 'a future time must never become "the newest reading"');
        $this->assertDatabaseHas('vehicle_odometer_readings', ['anomaly_code' => 'FUTURE_TIME', 'disposition' => 'quarantined', 'review_status' => null, 'effective_km' => null]);

        $this->assertSame('applied', $this->rec('12010', 0, received: $received, at: $received->subMinutes(5)));
        $this->assertSame('12010.00', $this->odometer());
    }

    public function test_a_reading_older_than_what_is_held_is_late_and_never_applied(): void
    {
        $this->rec('12000', 5);

        $this->assertSame('late', $this->rec('15000', 3)); // higher, but measured before the one already held
        $this->assertSame('late', $this->rec('11000', 2));

        $this->assertSame('12000.00', $this->odometer());
        $link = $this->link();
        $this->assertTrue($link->last_measured_at->equalTo($this->t(5)));
        $this->assertSame('12000.00', $link->baseline('DEVICE_ODOMETER')['km']);
        $this->assertFalse($link->underReview());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '15000.00', 'disposition' => 'late', 'applied' => false]);
        $this->assertSame('applied', $this->rec('12050', 6));
    }

    public function test_equal_measurement_times_with_a_consistent_value_are_ordinary_readings(): void
    {
        $this->rec('12000', 1);

        $this->assertSame('applied', $this->rec('12010', 1));
    }

    public function test_a_malformed_item_is_rejected_not_zero_filled_and_never_throws(): void
    {
        $svc = app(VehicleOdometerService::class);
        $good = ['reading_id' => (string) Str::uuid(), 'vehicle_id' => $this->vehicle->id, 'odometer_km' => '12000', 'recorded_at' => $this->t(1)->toIso8601String(), 'device_ref' => '7'];

        foreach ([
            ['INVALID_ODOMETER', ['odometer_km' => null]],
            ['INVALID_ODOMETER', ['odometer_km' => 'abc']],
            ['INVALID_ODOMETER', ['odometer_km' => '-1']],
            ['INVALID_TIME', ['recorded_at' => 'not a time']],
            ['INVALID_VEHICLE_ID', ['vehicle_id' => '123']],
            ['INVALID_READING_ID', ['reading_id' => 'x']],
            ['INVALID_DEVICE_REF', ['device_ref' => '']],
            ['INVALID_KIND', ['odometer_kind' => 'SOMETHING']],
        ] as [$expected, $override]) {
            $item = $override + $good;
            $this->assertSame($expected, $svc->invalidReason($item), json_encode($override));
            $this->assertSame('rejected', $svc->record($this->tenant->id, $item));
        }
        $missing = $good;
        unset($missing['odometer_km']);
        $this->assertSame('rejected', $svc->record($this->tenant->id, $missing));

        $this->assertSame(0, VehicleOdometerReading::query()->count());
        $this->assertSame('10000.00', $this->odometer());
    }

    // ---------------------------------------------------------------- review decisions

    private function fall(): void
    {
        $this->rec('12000', 1);
        $this->rec('11000', 2);
    }

    public function test_dismissing_keeps_everything_as_it_was_and_the_feed_continues(): void
    {
        $this->fall();

        $r = $this->review('dismiss')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.review', null);

        $this->assertSame('12000.00', $this->odometer());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '11000.00', 'disposition' => 'quarantined', 'review_status' => 'dismissed', 'review_note' => 'Checked with the workshop on site']);
        $this->assertSame('applied', $this->rec('12100', 4));
        $this->assertNotNull($r->json('data.id'));
        $this->assertTrue(AuditLog::query()->where('action', 'telematics.review_dismiss')->where('resource_id', $this->link()->id)->exists());
    }

    public function test_accepting_a_higher_reading_makes_it_the_odometer(): void
    {
        $this->rec('12000', 1);
        $this->assertSame('quarantined', $this->rec('17000', 2));

        $this->review('accept')->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame('17000.00', $this->odometer());
        $this->assertSame('optinexus', $this->vehicle->fresh()->odometer_source);
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '17000.00', 'disposition' => 'applied', 'applied' => true, 'review_status' => 'accepted', 'effective_km' => '17000.00', 'previous_odometer' => '12000.00']);
        $this->assertSame('applied', $this->rec('17100', 3));
    }

    public function test_accepting_a_lower_reading_is_a_correction_that_needs_a_real_reason(): void
    {
        $this->fall();

        $this->review('accept', 'ok ok')->assertStatus(422)->assertJsonPath('code', 'ODOMETER_CORRECTION_REASON_REQUIRED');
        $this->assertSame('12000.00', $this->odometer());
        $this->assertTrue($this->link()->underReview(), 'a refused decision changes nothing');

        $this->review('accept', 'The dashboard was swapped; the device shows the true value')->assertOk();

        $this->assertSame('11000.00', $this->odometer());
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '11000.00', 'disposition' => 'correction', 'review_status' => 'accepted']);
        $audit = AuditLog::query()->where('action', 'odometer_corrected')->where('resource_id', $this->vehicle->id)->firstOrFail();
        $this->assertSame('11000.00', $audit->new_values['current_odometer']);
    }

    public function test_rebasing_a_replaced_device_aligns_it_to_the_odometer_and_never_lowers_it(): void
    {
        $this->rec('12000', 1, device: '7');
        $this->assertSame('quarantined', $this->rec('100', 2, device: '8'));

        $this->review('rebase', device: '8')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.rebase_offset_km', '11900.00');

        $this->assertSame('12000.00', $this->odometer(), 'aligning a device does not move the odometer');
        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '100.00', 'review_status' => 'rebased', 'effective_km' => '12000.00', 'disposition' => 'stored']);

        // The new device drives on: 150 on its own counter is 12,050 for the vehicle.
        $this->assertSame('applied', $this->rec('150', 3, device: '8'));
        $this->assertSame('12050.00', $this->odometer());
    }

    public function test_rebasing_can_be_given_the_true_odometer_but_not_a_lower_one(): void
    {
        $this->rec('12000', 1, device: '7');
        $this->rec('100', 2, device: '8');

        $this->review('rebase', extra: ['actual_odometer_km' => 11000], device: '8')->assertStatus(422)->assertJsonPath('code', 'ODOMETER_BELOW_CURRENT');
        $this->assertTrue($this->link('8')->underReview());

        $this->review('rebase', extra: ['actual_odometer_km' => 12100], device: '8')->assertOk()->assertJsonPath('data.rebase_offset_km', '12000.00');
        $this->assertSame('12100.00', $this->odometer());
    }

    public function test_rebasing_a_gps_device_recalibrates_it(): void
    {
        $this->rec('800', 1, 'GPS_DISTANCE');
        app(VehicleOdometerService::class)->calibrate($this->link(), '9200', null, null); // 800 + 9200 = 10000
        $this->assertSame('applied', $this->rec('900', 2, 'GPS_DISTANCE'));          // 10100
        $this->assertSame('quarantined', $this->rec('50', 3, 'GPS_DISTANCE'));         // tracker was reset

        $this->review('rebase')->assertOk()->assertJsonPath('data.odometer_offset_km', '10050.00');

        $this->assertSame('10100.00', $this->odometer());
        $this->assertSame('applied', $this->rec('60', 4, 'GPS_DISTANCE'));
        $this->assertSame('10110.00', $this->odometer());
    }

    public function test_accepting_an_uncalibrated_gps_reading_is_refused(): void
    {
        $this->rec('800', 1, 'GPS_DISTANCE');
        $this->rec('700', 2, 'GPS_DISTANCE');
        $this->assertTrue($this->link()->underReview());

        $this->review('accept')->assertStatus(422)->assertJsonPath('code', 'TELEMATICS_CALIBRATION_REQUIRED');
    }

    public function test_calibration_waits_for_the_review_and_never_carries_a_quarantined_reading(): void
    {
        $this->rec('800', 1, 'GPS_DISTANCE');
        $this->rec('50', 2, 'GPS_DISTANCE'); // reset, quarantined
        $link = $this->link();

        $this->putJson("/api/v1/app/telematics-links/{$link->id}/calibration", ['odometer_offset_km' => 9500], $this->authHeaders($this->token()))
            ->assertStatus(409)->assertJsonPath('code', 'TELEMATICS_REVIEW_PENDING');
        $this->assertNull($link->fresh()->odometer_offset_km);

        $this->review('dismiss')->assertOk();
        $this->putJson("/api/v1/app/telematics-links/{$link->id}/calibration", ['odometer_offset_km' => 9500], $this->authHeaders($this->token()))->assertOk();

        $this->assertDatabaseHas('vehicle_odometer_readings', ['reported_km' => '50.00', 'disposition' => 'quarantined', 'effective_km' => null]);
        $this->assertSame('10300.00', $this->odometer(), 'only the 800 km reading (+9500) was used');
    }

    public function test_a_device_that_is_not_under_review_has_nothing_to_decide(): void
    {
        $this->rec('12000', 1);

        $this->review('dismiss')->assertStatus(409)->assertJsonPath('code', 'TELEMATICS_NOT_UNDER_REVIEW');
    }

    public function test_the_decision_and_the_note_are_validated(): void
    {
        $this->fall();
        $link = $this->link();
        $headers = $this->authHeaders($this->token());

        $this->postJson("/api/v1/app/telematics-links/{$link->id}/review", ['decision' => 'delete', 'note' => 'whatever note'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/telematics-links/{$link->id}/review", ['decision' => 'dismiss'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/telematics-links/{$link->id}/review", ['decision' => 'dismiss', 'note' => 'ok'], $headers)->assertStatus(422)->assertJsonPath('code', 'TELEMATICS_REVIEW_NOTE_REQUIRED');
        $this->assertTrue($link->fresh()->underReview());
    }

    // ---------------------------------------------------------------- API surface

    public function test_the_api_shows_what_needs_a_person_and_why(): void
    {
        $this->fall();
        $headers = $this->authHeaders($this->token());

        $list = $this->getJson('/api/v1/app/telematics-links?review=true', $headers)->assertOk()->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.status', 'review')
            ->assertJsonPath('data.0.review.reason', 'COUNTER_DECREASE')
            ->assertJsonPath('data.0.review.reading.reported_km', '11000.00')
            ->assertJsonPath('data.0.review.reading.quality', 'SUSPECT')
            ->assertJsonPath('data.0.latest_reading.disposition', 'quarantined')
            ->assertJsonPath('data.0.latest_reading.unit', 'km')
            ->assertJsonPath('data.0.has_readings', true);

        $this->getJson('/api/v1/app/telematics/status', $headers)->assertOk()
            ->assertJsonPath('data.devices.under_review', 1)->assertJsonPath('data.quarantined_last_7_days', 1);

        $this->review('dismiss')->assertOk();
        $this->getJson('/api/v1/app/telematics-links?review=true', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_review_needs_the_manage_permission_the_right_tenant_and_branch_scope(): void
    {
        $this->fall();
        $link = $this->link();
        $body = ['decision' => 'dismiss', 'note' => 'seen and checked'];

        $this->postJson("/api/v1/app/telematics-links/{$link->id}/review", $body, $this->authHeaders($this->token(['telematics_link.view'])))->assertStatus(403);

        $other = $this->makeTenant();
        $this->grantModule($other, 'VEHICLE');
        $this->postJson("/api/v1/app/telematics-links/{$link->id}/review", $body, $this->authHeaders($this->token(['telematics_link.view', 'telematics_link.manage'], $other)))->assertStatus(404);

        $otherBranch = $this->makeBranch($this->tenant);
        $scoped = $this->token(['telematics_link.view', 'telematics_link.manage'], null, ['BRANCH' => $otherBranch->id]);
        $this->postJson("/api/v1/app/telematics-links/{$link->id}/review", $body, $this->authHeaders($scoped))->assertStatus(403);
        $this->getJson('/api/v1/app/telematics-links', $this->authHeaders($scoped))->assertOk()->assertJsonCount(0, 'data');

        $this->assertTrue($link->fresh()->underReview());
    }
}
