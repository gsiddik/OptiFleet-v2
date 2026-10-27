<?php

namespace App\Domain\Integration\Master;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who drove a vehicle, as OptiDeli reports it (docs/integration/MASTER_DATA_SYNC.md, stage 4). OptiDeli owns the drivers and the
 * allocation; OptiFleet keeps no driver register and only a history per vehicle: one row per fact OptiDeli published (a trip that left,
 * a day worked), a snapshot of the driver (name, phone, licence number masked to its last four characters). Facts are matched to a
 * vehicle by plate key when read, so one arriving before the vehicle's own event is not lost. Periods (the same driver over several
 * trips) are worked out when reading, so facts may arrive in any order and twice. The history exists only for a tenant that has
 * bought OptiDeli.
 */
final class VehicleDrivers
{
    public const RECEIVED = 'optideli.vehicle.driver_assigned';

    private const REFERENCES = ['SPM_TRIP', 'ORDER_TRIP', 'ATTENDANCE'];

    private const READ_LIMIT = 1000;

    public function __construct(private readonly CentralGovernance $central) {}

    /** Whether the tenant has bought OptiDeli, as OptiNexus says right now (cached by CentralGovernance). False when it cannot be told. */
    public function available(string $tenantId): bool
    {
        try {
            return in_array('optideli', $this->central->commercial($tenantId)['applications'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return 'applied'|'ignored' */
    public function apply(array $event): string
    {
        $payload = $event['data']['payload'] ?? null;
        if (($event['event_type'] ?? null) !== self::RECEIVED || ($event['source_system'] ?? null) !== 'optideli' || ! is_array($payload)) {
            return 'ignored';
        }
        $plate = trim((string) ($payload['registration_number'] ?? ''));
        $key = VehicleMaster::key($plate);
        $reference = is_array($payload['reference'] ?? null) ? $payload['reference'] : [];
        $driver = is_array($payload['driver'] ?? null) ? $payload['driver'] : [];
        $name = $this->text($driver['name'] ?? null, 150);
        $tenant = Tenant::query()->where('optinexus_tenant_id', (string) ($event['tenant_id'] ?? ''))->first();
        try {
            $at = CarbonImmutable::parse((string) ($payload['assigned_at'] ?? ''));
        } catch (Throwable) {
            return 'ignored';
        }
        if ($key === '' || ! $tenant || $name === null || ! in_array($reference['type'] ?? null, self::REFERENCES, true) || ! Str::isUuid((string) ($reference['id'] ?? ''))) {
            return 'ignored';
        }

        $values = [
            'registration_key' => $key, 'assigned_at' => $at->utc(), 'reference_number' => $this->text($reference['number'] ?? null, 60),
            'driver_name' => $name, 'driver_phone' => $this->text($driver['phone'] ?? null, 40), 'license_masked' => $this->text($driver['license_masked'] ?? null, 20),
            'updated_at' => now(),
        ];
        DB::table('vehicle_driver_assignments')->upsert(
            [$values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'reference_type' => $reference['type'], 'reference_id' => $reference['id'], 'created_at' => now()]],
            ['tenant_id', 'reference_type', 'reference_id'], array_keys($values),
        );

        return 'applied';
    }

    /**
     * The drivers of a vehicle, newest period first: the same driver on consecutive facts is one period.
     *
     * @return list<array{driver_name: string, driver_phone: ?string, license_masked: ?string, first_assigned_at: string, last_assigned_at: string, assignments: int, last_reference: ?string}>
     */
    public function history(Vehicle $vehicle): array
    {
        $rows = $this->rows($vehicle)->orderBy('assigned_at')->orderBy('created_at')->get();
        $periods = [];
        foreach ($rows as $row) {
            $last = count($periods) - 1;
            if ($last >= 0 && $periods[$last]['driver_name'] === $row->driver_name && $periods[$last]['license_masked'] === $row->license_masked) {
                $periods[$last]['last_assigned_at'] = $this->iso($row->assigned_at);
                $periods[$last]['assignments']++;
                $periods[$last]['last_reference'] = $row->reference_number;

                continue;
            }
            $periods[] = [
                'driver_name' => $row->driver_name, 'driver_phone' => $row->driver_phone, 'license_masked' => $row->license_masked,
                'first_assigned_at' => $this->iso($row->assigned_at), 'last_assigned_at' => $this->iso($row->assigned_at), 'assignments' => 1, 'last_reference' => $row->reference_number,
            ];
        }

        return array_reverse(array_slice($periods, -self::READ_LIMIT));
    }

    /** The driver who last had the vehicle at or before the moment, or null. @return array{driver_name: string, driver_phone: ?string, license_masked: ?string, assigned_at: string}|null */
    public function lastBefore(Vehicle $vehicle, CarbonInterface $at): ?array
    {
        $row = $this->rows($vehicle)->where('assigned_at', '<=', $at->toDateTimeString())->orderByDesc('assigned_at')->first();

        return $row ? ['driver_name' => $row->driver_name, 'driver_phone' => $row->driver_phone, 'license_masked' => $row->license_masked, 'assigned_at' => $this->iso($row->assigned_at)] : null;
    }

    private function rows(Vehicle $vehicle)
    {
        return DB::table('vehicle_driver_assignments')->where('tenant_id', $vehicle->tenant_id)->where('registration_key', VehicleMaster::key($vehicle->registration_number));
    }

    private function iso(mixed $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->format('Y-m-d\TH:i:s\Z');
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $max, '') : null;
    }
}
