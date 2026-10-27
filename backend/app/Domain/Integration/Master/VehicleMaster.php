<?php

namespace App\Domain\Integration\Master;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Services\IntegrationOutboxService;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The vehicle master data shared with the other platforms (OptiNexus docs/integration/MASTER_DATA_SYNC.md). OptiFleet owns the
 * asset group and publishes it through the integration outbox (`vehicle.upserted`, relayed to OptiNexus like every other fact).
 * It takes the transport group from OptiDeli and keeps it beside the asset. A vehicle OptiDeli announces that OptiFleet does not
 * know becomes a DRAFT that staff complete; a draft is outside the subscription capacity and publishes nothing until completed.
 * What is applied from another platform is never published again.
 */
final class VehicleMaster
{
    public const EVENT_TYPE = 'vehicle.upserted';

    public const RECEIVED = 'optideli.vehicle.upserted';

    public function __construct(private readonly IntegrationOutboxService $outbox) {}

    /** Queues the vehicle's asset data for the other platforms. Does nothing for an unlinked tenant or a draft. Call inside the caller's transaction. */
    public function publish(Vehicle $vehicle): bool
    {
        if ($vehicle->status === 'DRAFT' || ! Tenant::query()->whereKey($vehicle->tenant_id)->whereNotNull('optinexus_tenant_id')->exists()) {
            return false;
        }

        $this->outbox->record(
            $vehicle->tenant_id, self::EVENT_TYPE, 'Vehicle', (string) Str::uuid(), ['vehicle_id' => $vehicle->id],
            [
                'key' => self::key($vehicle->registration_number), 'registration_number' => $vehicle->registration_number,
                'version' => (int) (microtime(true) * 1000), 'changed_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'asset' => [
                    'brand' => $vehicle->brand, 'model' => $vehicle->model, 'year' => $vehicle->year, 'vin' => $vehicle->vin,
                    'chassis_number' => $vehicle->chassis_number, 'engine_number' => $vehicle->engine_number, 'fuel_type' => $vehicle->fuel_type,
                ],
            ],
        );

        return true;
    }

    /** Queues every complete vehicle of linked tenants (first start-up of the sync, or after an outage). @return int vehicles queued */
    public function publishAll(?string $tenantId = null): int
    {
        $count = 0;
        Vehicle::query()->withoutGlobalScopes()->where('status', '!=', 'DRAFT')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')->each(function (Vehicle $v) use (&$count) {
                $count += DB::transaction(fn () => $this->publish($v)) ? 1 : 0;
            });

        return $count;
    }

    /** @return 'applied'|'created'|'stale'|'ignored' */
    public function apply(array $event): string
    {
        $payload = $event['data']['payload'] ?? null;
        if (($event['event_type'] ?? null) !== self::RECEIVED || ($event['source_system'] ?? null) !== 'optideli' || ! is_array($payload)) {
            return 'ignored';
        }
        $plate = trim((string) ($payload['registration_number'] ?? ''));
        $key = self::key($plate);
        $version = $payload['version'] ?? null;
        $transport = $payload['transport'] ?? null;
        $tenant = Tenant::query()->where('optinexus_tenant_id', (string) ($event['tenant_id'] ?? ''))->first();
        if ($key === '' || ! is_int($version) || ! is_array($transport) || ! $tenant) {
            return 'ignored';
        }

        return DB::transaction(function () use ($tenant, $key, $plate, $version, $transport) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
            $seen = DB::table('master_versions')->where(['tenant_id' => $tenant->id, 'registration_key' => $key, 'source' => 'OPTIDELI'])->value('version');
            if ($seen !== null && $seen >= $version) {
                return 'stale';
            }
            DB::table('master_versions')->upsert([['tenant_id' => $tenant->id, 'registration_key' => $key, 'source' => 'OPTIDELI', 'version' => $version]], ['tenant_id', 'registration_key', 'source'], ['version']);

            $vehicle = DB::table('vehicles')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
                ->whereRaw("regexp_replace(upper(registration_number), '[^A-Z0-9]', '', 'g') = ?", [$key])->first();
            $result = 'applied';
            if (! $vehicle) {
                $id = (string) Str::uuid();
                DB::table('vehicles')->insert([
                    'id' => $id, 'tenant_id' => $tenant->id, 'registration_number' => $plate, 'status' => 'DRAFT', 'operational_status' => 'AVAILABLE',
                    'source_application' => 'OPTIDELI', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $vehicle = (object) ['id' => $id];
                $result = 'created';
            }

            $values = [
                'carrier_type_code' => $this->text($transport['carrier_type_code'] ?? null, 80),
                'capacity_g' => $this->number($transport['capacity_g'] ?? null), 'length_mm' => $this->number($transport['length_mm'] ?? null),
                'width_mm' => $this->number($transport['width_mm'] ?? null), 'height_mm' => $this->number($transport['height_mm'] ?? null),
                'source_version' => $version, 'updated_at' => now(),
            ];
            DB::table('vehicle_transport_profiles')->upsert(
                [$values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'created_at' => now()]],
                ['tenant_id', 'vehicle_id'], array_keys($values),
            );

            return $result;
        });
    }

    /** Plates are compared without case, spaces or dashes: "B 1234 XY" and "b-1234-xy" are the same vehicle. */
    public static function key(string $registrationNumber): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper($registrationNumber)) ?? '';
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $max, '') : null;
    }

    private function number(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
