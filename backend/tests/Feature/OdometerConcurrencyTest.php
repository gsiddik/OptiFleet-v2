<?php

namespace Tests\Feature;

use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit A10: the odometer stays monotonic and every reading is recorded once when several processes offer values at the same time
 * (two sync workers reading the same pages, an inspection, a work order, a person typing). Real PostgreSQL, real parallel PHP
 * processes with their own connections and transactions; the vehicle row lock is what is under test.
 *
 * The test transaction of RefreshDatabase is invisible to other processes, so the fixture is committed through a separate connection
 * and removed again afterwards.
 */
class OdometerConcurrencyTest extends TestCase
{
    private const WORKER = 'tests/Concurrency/odometer_worker.php';

    private ?string $tenantId = null;

    private ?string $categoryId = null;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        if ($this->tenantId) {
            $db = DB::connection('concurrency');
            foreach (['vehicle_odometer_readings', 'vehicle_telematics_links', 'audit_logs', 'vehicles', 'branches'] as $table) {
                $db->table($table)->where('tenant_id', $this->tenantId)->delete();
            }
            $db->table('tenants')->where('id', $this->tenantId)->delete();
            $db->table('vehicle_categories')->where('id', $this->categoryId)->delete();
            $db->disconnect();
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** A committed tenant with one vehicle at 10,000 km. @return array{0: string, 1: string} */
    private function fixture(): array
    {
        config(['database.connections.concurrency' => config('database.connections.pgsql')]);
        $db = DB::connection('concurrency');
        $this->tenantId = (string) Str::uuid();
        $this->categoryId = (string) Str::uuid();
        $branchId = (string) Str::uuid();
        $vehicleId = (string) Str::uuid();
        $now = now();
        $db->table('tenants')->insert(['id' => $this->tenantId, 'code' => 'ODO-'.Str::random(6), 'name' => 'Odometer concurrency', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
        $db->table('vehicle_categories')->insert(['id' => $this->categoryId, 'code' => 'VC-'.Str::random(6), 'name' => 'Cat', 'is_system' => true, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
        $db->table('branches')->insert(['id' => $branchId, 'tenant_id' => $this->tenantId, 'code' => 'BR-'.Str::random(5), 'name' => 'Branch', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
        $db->table('vehicles')->insert([
            'id' => $vehicleId, 'tenant_id' => $this->tenantId, 'branch_id' => $branchId, 'vehicle_category_id' => $this->categoryId, 'brand' => 'B', 'model' => 'M',
            'registration_number' => 'REG-'.Str::random(6), 'current_odometer' => '10000.00', 'status' => 'ACTIVE', 'operational_status' => 'AVAILABLE', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$this->tenantId, $vehicleId];
    }

    private function writeJson(array $values): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fleet-odo-');
        file_put_contents($file, json_encode($values));
        $this->files[] = $file;

        return $file;
    }

    /** @return array{0: resource, 1: array<int, resource>, 2: string} */
    private function start(string $mode, string $label, string $tenantId, string $vehicleId, string $file, float $startAt, int $seed): array
    {
        $env = getenv();
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE'] as $key) {
            if (($value = env($key)) !== null) {
                $env[$key] = (string) $value;
            }
        }
        $process = proc_open(
            [PHP_BINARY, base_path(self::WORKER), $mode, $label, $tenantId, $vehicleId, $file, sprintf('%.6F', $startAt), (string) $seed],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env,
        );

        return [$process, $pipes, $label];
    }

    /** Waits for the worker, returns [exit code, its JSON lines, stderr]. */
    private function wait(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, array_values(array_filter(array_map(fn (string $l) => json_decode($l, true), explode("\n", trim($out))), 'is_array')), $err];
    }

    public function test_parallel_workers_reading_the_same_pages_record_each_reading_once_and_end_at_the_highest_value(): void
    {
        [$tenantId, $vehicleId] = $this->fixture();
        $base = CarbonImmutable::parse('2026-10-01T00:00:00Z');
        // 60 readings of one device, a minute apart, each 0.5 km further: plausible, in order of time.
        $items = [];
        foreach (range(1, 60) as $i) {
            $items[] = [
                'reading_id' => (string) Str::uuid(), 'vehicle_id' => $vehicleId, 'odometer_km' => number_format(10000 + $i * 0.5, 2, '.', ''), 'odometer_kind' => 'DEVICE_ODOMETER',
                'recorded_at' => $base->addMinutes($i)->toIso8601String(), 'source' => 'optiradar', 'device_ref' => '7',
            ];
        }
        $file = $this->writeJson($items);

        $startAt = microtime(true) + 3;
        $workers = [];
        foreach ([1, 2, 3, 4] as $n) {
            $workers[] = $this->start('telematics', "t{$n}", $tenantId, $vehicleId, $file, $startAt, $n * 101);
        }
        $results = [];
        foreach ($workers as $w) {
            [$code, $lines, $err] = $this->wait($w);
            $this->assertSame(0, $code, $err);
            $this->assertSame([], array_filter($lines, fn ($l) => isset($l['error'])), 'no worker hit an error (a duplicate key would be one)');
            $results = array_merge($results, array_column($lines, 'result'));
        }

        $this->assertCount(4 * 60, $results);
        $counts = array_count_values($results);
        $this->assertSame(60 * 3, $counts['duplicate'] ?? 0, 'every reading was accepted by exactly one worker and seen as a duplicate by the other three');
        $this->assertSame(60, VehicleOdometerReading::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
        $this->assertSame(60, VehicleOdometerReading::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->distinct()->count('source_reading_id'));
        $this->assertSame('10030.00', (string) Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicleId)->current_odometer, 'the highest value of the newest reading wins whatever the order of arrival');
        $this->assertSame(0, VehicleOdometerReading::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('disposition', 'quarantined')->count(), 'out-of-order arrival is "late", not an anomaly');
        $this->assertChain($tenantId, '10000.00', '10030.00');
    }

    public function test_telematics_inspections_work_orders_and_people_racing_never_lower_the_odometer(): void
    {
        [$tenantId, $vehicleId] = $this->fixture();
        $base = CarbonImmutable::parse('2026-10-01T00:00:00Z');
        $telematics = [];
        foreach (range(1, 20) as $i) {
            $telematics[] = [
                'reading_id' => (string) Str::uuid(), 'vehicle_id' => $vehicleId, 'odometer_km' => number_format(10000 + $i * 10, 2, '.', ''), 'odometer_kind' => 'DEVICE_ODOMETER',
                'recorded_at' => $base->addHours($i)->toIso8601String(), 'source' => 'optiradar', 'device_ref' => '7',
            ];
        }
        $operations = $this->writeJson(array_map(fn ($i) => (string) (10000 + $i * 7), range(1, 25)));  // up to 10175
        $manual = $this->writeJson(array_map(fn ($i) => (string) (10000 + $i * 9), range(1, 25)));      // up to 10225
        $feed = $this->writeJson($telematics);                                                           // up to 10200

        $startAt = microtime(true) + 3;
        $workers = [
            $this->start('telematics', 'feed', $tenantId, $vehicleId, $feed, $startAt, 7),
            $this->start('operations', 'ops', $tenantId, $vehicleId, $operations, $startAt, 8),
            $this->start('manual', 'person', $tenantId, $vehicleId, $manual, $startAt, 9),
        ];
        foreach ($workers as $i => $w) {
            [$code, $lines, $err] = $this->wait($w);
            $this->assertSame(0, $code, $err);
            // A person offering a value below what a faster writer just stored is refused (it would need a correction reason): that is the
            // rule working, not a failure. Any other error (a deadlock, a duplicate key, a lost update) is.
            $errors = array_filter($lines, fn ($l) => isset($l['error']) && ! ($l['worker'] === 'person' && str_ends_with($l['error'], 'ODOMETER_BELOW_CURRENT')));
            $this->assertSame([], array_values($errors), json_encode($lines));
            $this->assertCount($i === 0 ? 20 : 25, $lines, 'every offered value got an answer');
        }

        $final = (string) Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicleId)->current_odometer;
        $this->assertSame('10225.00', $final, 'the highest value offered by anyone');
        $this->assertSame(0, VehicleOdometerReading::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('disposition', 'correction')->count(), 'nobody lowered it');
        $this->assertChain($tenantId, '10000.00', '10225.00');
    }

    /**
     * Every row that moved the odometer says what it moved it from: ordered by value they form one unbroken chain from the start to the
     * end. Two writers deciding against the same old value (a lost update) would break it.
     */
    private function assertChain(string $tenantId, string $start, string $end): void
    {
        $rows = VehicleOdometerReading::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('applied', true)->orderBy('effective_km')->get();
        $this->assertGreaterThan(0, $rows->count());
        $previous = $start;
        foreach ($rows as $row) {
            $this->assertSame($previous, (string) $row->previous_odometer, "row {$row->effective_km}: it must start from what the odometer was before it");
            $this->assertTrue((float) $row->effective_km > (float) $row->previous_odometer, 'an applied row always raised the odometer');
            $previous = (string) $row->effective_km;
        }
        $this->assertSame($end, $previous);
    }
}
