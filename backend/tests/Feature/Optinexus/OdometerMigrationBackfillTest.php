<?php

namespace Tests\Feature\Optinexus;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit A10, migration 2026_10_25_000001: data written before the migration keeps its meaning. Postgres DDL is transactional, so the
 * test rolls the migration back inside the test transaction, writes the rows the old schema allowed, and runs it again.
 */
class OdometerMigrationBackfillTest extends TestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_25_000001_odometer_provenance_and_telematics_state.php');
    }

    public function test_rows_written_before_the_migration_get_only_what_they_already_say(): void
    {
        $synced = $this->makeTenant();
        $neverSynced = $this->makeTenant();
        $noFeed = $this->makeTenant();
        $branch = $this->makeBranch($synced);
        $vehicle = $this->makeVehicle($synced, $branch, $this->makeVehicleCategory(), ['current_odometer' => 5000]);

        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tenants', 'telematics_activated_at'));
        $this->assertFalse(Schema::hasColumn('vehicle_odometer_readings', 'disposition'));

        $firstSync = CarbonImmutable::parse('2026-09-01 10:00:00');
        $row = fn (array $extra) => $extra + [
            'id' => (string) Str::uuid(), 'tenant_id' => $synced->id, 'vehicle_id' => $vehicle->id, 'source' => 'optinexus', 'source_reading_id' => (string) Str::uuid(),
            'device_ref' => '7', 'odometer_kind' => 'DEVICE_ODOMETER', 'reported_km' => '5000.00', 'previous_odometer' => '4000.00',
            'recorded_at' => $firstSync->subHour(), 'created_at' => $firstSync, 'updated_at' => $firstSync,
        ];
        $applied = $row(['effective_km' => '5000.00', 'applied' => true]);
        $stored = $row(['effective_km' => '4900.00', 'applied' => false]);
        $held = $row(['effective_km' => null, 'applied' => false, 'odometer_kind' => 'GPS_DISTANCE']);
        DB::table('vehicle_odometer_readings')->insert([$applied, $stored, $held]);

        DB::table('integration_sync_cursors')->insert([
            ['id' => (string) Str::uuid(), 'tenant_id' => $synced->id, 'stream' => 'optinexus.odometer', 'cursor' => 12, 'last_synced_at' => $firstSync->addDay(), 'last_error' => null, 'created_at' => $firstSync, 'updated_at' => $firstSync],
            ['id' => (string) Str::uuid(), 'tenant_id' => $neverSynced->id, 'stream' => 'optinexus.odometer', 'cursor' => 0, 'last_synced_at' => null, 'last_error' => 'boom', 'created_at' => $firstSync, 'updated_at' => $firstSync],
        ]);
        DB::table('vehicle_telematics_links')->insert([
            'id' => $linkId = (string) Str::uuid(), 'tenant_id' => $synced->id, 'vehicle_id' => $vehicle->id, 'source' => 'optinexus', 'device_ref' => '7',
            'odometer_offset_km' => '12.50', 'created_at' => $firstSync, 'updated_at' => $firstSync,
        ]);

        $migration->up();

        // Dispositions follow from `applied` / `effective_km`; received_at is when the row was written; nothing is invented.
        $disposition = fn (array $r) => DB::table('vehicle_odometer_readings')->where('id', $r['id'])->first();
        $this->assertSame('applied', $disposition($applied)->disposition);
        $this->assertSame('stored', $disposition($stored)->disposition);
        $this->assertSame('held', $disposition($held)->disposition);
        foreach ([$applied, $stored, $held] as $r) {
            $after = $disposition($r);
            $this->assertTrue(CarbonImmutable::parse($after->received_at)->equalTo($firstSync), 'received_at = created_at');
            $this->assertSame('km', $after->unit);
            $this->assertNull($after->anomaly_code);
            $this->assertNull($after->review_status);
            $this->assertNull($after->origin, 'the origin of an old row is not guessed');
            $this->assertSame('5000.00', $after->reported_km);
        }

        // A feed that had synced keeps working (activated at its first row); one that never synced, or never existed, is not switched on.
        $activated = fn ($tenant) => DB::table('tenants')->where('id', $tenant->id)->value('telematics_activated_at');
        $this->assertTrue(CarbonImmutable::parse($activated($synced))->equalTo($firstSync));
        $this->assertNull($activated($neverSynced));
        $this->assertNull($activated($noFeed));
        $this->assertNull(DB::table('tenants')->where('id', $synced->id)->value('telematics_activated_by'));

        // The vehicle's odometer is untouched and its origin is unknown (NULL), not "manual".
        $v = DB::table('vehicles')->where('id', $vehicle->id)->first();
        $this->assertSame('5000.00', $v->current_odometer);
        $this->assertNull($v->odometer_source);
        $this->assertNull($v->odometer_measured_at);

        // The old link is active, calibrated as before, with no baseline: the first reading after the migration sets one.
        $link = DB::table('vehicle_telematics_links')->where('id', $linkId)->first();
        $this->assertSame('active', $link->status);
        $this->assertSame('12.50', $link->odometer_offset_km);
        $this->assertNull($link->baselines);
        $this->assertNull($link->rebase_offset_km);

        $cursor = DB::table('integration_sync_cursors')->where('tenant_id', $neverSynced->id)->first();
        $this->assertSame(0, $cursor->consecutive_failures);
        $this->assertSame('boom', $cursor->last_error, 'the old error text stays');
        $this->assertNull($cursor->last_status);
    }

    public function test_the_database_refuses_values_outside_the_vocabulary(): void
    {
        $tenant = $this->makeTenant();
        $vehicle = $this->makeVehicle($tenant, $this->makeBranch($tenant), $this->makeVehicleCategory());
        $base = [
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'source' => 'manual', 'device_ref' => null, 'odometer_kind' => null,
            'reported_km' => '1.00', 'previous_odometer' => '0.00', 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
        $insert = fn (array $extra) => DB::table('vehicle_odometer_readings')->insert($extra + $base + ['id' => (string) Str::uuid(), 'source_reading_id' => (string) Str::uuid()]);

        $insert(['disposition' => 'applied', 'applied' => true, 'effective_km' => '1.00']);   // valid, and a manual row needs no device
        foreach ([['disposition' => 'maybe'], ['disposition' => 'stored', 'review_status' => 'later'], ['disposition' => 'stored', 'unit' => 'mi']] as $bad) {
            try {
                DB::transaction(fn () => $insert($bad));
                $this->fail('accepted '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertStringContainsString('check constraint', $e->getMessage());
            }
        }
        try {
            DB::transaction(fn () => DB::table('vehicle_telematics_links')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'source' => 'optinexus', 'device_ref' => '1', 'status' => 'broken',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('accepted a link status outside active/review');
        } catch (QueryException $e) {
            $this->assertStringContainsString('check constraint', $e->getMessage());
        }
    }
}
