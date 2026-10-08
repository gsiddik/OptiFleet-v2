<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationSyncCursor;
use App\Domain\Vehicle\Models\Vehicle;
use Throwable;

/**
 * Per-tenant exchange with the OptiNexus gateway: publish the vehicle
 * directory, then pull telematics readings from the saved cursor. Safe to
 * re-run: publishing is an upsert and applying a reading is idempotent, so
 * a crash between "apply" and "save cursor" only repeats harmless work.
 */
class OptinexusSyncService
{
    public const STREAM = 'optinexus.odometer';

    private const MAX_PAGES_PER_RUN = 50;

    public function __construct(
        private readonly OptinexusGatewayClient $gateway,
        private readonly VehicleOdometerService $odometer,
    ) {}

    /**
     * @return array{published: int, applied: int, stored: int, held: int, unknown: int, duplicates: int}
     */
    public function syncTenant(Tenant $tenant): array
    {
        $stats = ['published' => 0, 'applied' => 0, 'stored' => 0, 'held' => 0, 'unknown' => 0, 'duplicates' => 0];
        $cursor = IntegrationSyncCursor::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'stream' => self::STREAM],
            ['cursor' => 0],
        );

        try {
            $stats['published'] = $this->publishVehicles($tenant);
            $this->pull($tenant, $cursor, $stats);
            $cursor->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $e) {
            $cursor->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }

        return $stats;
    }

    private function publishVehicles(Tenant $tenant): int
    {
        $published = 0;

        Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunk(500, function ($vehicles) use ($tenant, &$published) {
                $this->gateway->publishVehicles($tenant->optinexus_tenant_id, $vehicles->map(fn (Vehicle $v) => [
                    'id' => $v->id,
                    'registration_number' => $v->registration_number,
                    'vin' => $v->vin,
                    'status' => $v->status,
                ])->all());
                $published += $vehicles->count();
            });

        return $published;
    }

    private function pull(Tenant $tenant, IntegrationSyncCursor $cursor, array &$stats): void
    {
        $limit = (int) config('optinexus.gateway.feed_page_size');

        for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
            $feed = $this->gateway->readings($tenant->optinexus_tenant_id, (int) $cursor->cursor, $limit);

            foreach ($feed['items'] as $item) {
                $result = $this->odometer->record($tenant->id, $item);
                $key = match ($result) {
                    VehicleOdometerService::RESULT_APPLIED => 'applied',
                    VehicleOdometerService::RESULT_STORED => 'stored',
                    VehicleOdometerService::RESULT_HELD => 'held',
                    VehicleOdometerService::RESULT_DUPLICATE => 'duplicates',
                    default => 'unknown',
                };
                $stats[$key]++;
            }

            $cursor->forceFill(['cursor' => (int) $feed['next_cursor']])->save();

            if (count($feed['items']) < $limit) {
                return;
            }
        }
    }
}
