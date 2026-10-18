<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationSyncCursor;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Per-tenant exchange with the OptiNexus gateway: publish the vehicle directory, then pull telematics readings from the saved
 * cursor. Safe to re-run: publishing is an upsert and applying a reading is idempotent, so a crash between "apply" and "save cursor"
 * only repeats harmless work.
 *
 * Only an eligible tenant is asked anything (TelematicsEligibility). Every run leaves its outcome on the cursor row (attempt time,
 * status, error code, failures in a row, counts, when a reading last arrived), so a feed that is silent, failing or refused is visible
 * instead of looking like "no data". A bad item (missing or malformed value) is counted and skipped; it never stops the feed and is
 * never turned into a zero.
 */
class OptinexusSyncService
{
    public const STREAM = 'optinexus.odometer';

    public const STATUS_OK = 'ok';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_ERROR = 'error';

    private const MAX_PAGES_PER_RUN = 50;

    public function __construct(
        private readonly OptinexusGatewayClient $gateway,
        private readonly VehicleOdometerService $odometer,
        private readonly TelematicsEligibility $eligibility,
    ) {}

    /**
     * @return array{state: string, published: int, applied: int, stored: int, held: int, late: int, quarantined: int, rejected: int, unknown: int, duplicates: int, received: int, rejected_sample: array<int, array{reading_id: ?string, reason: string}>}
     *
     * @throws Throwable when the gateway cannot be used (the failure is recorded first)
     */
    public function syncTenant(Tenant $tenant): array
    {
        $stats = ['state' => TelematicsEligibility::ELIGIBLE, 'published' => 0, 'applied' => 0, 'stored' => 0, 'held' => 0, 'late' => 0, 'quarantined' => 0,
            'rejected' => 0, 'unknown' => 0, 'duplicates' => 0, 'received' => 0, 'rejected_sample' => []];
        $cursor = IntegrationSyncCursor::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'stream' => self::STREAM],
            ['cursor' => 0],
        );

        $eligibility = $this->eligibility->check($tenant);
        if ($eligibility['state'] !== TelematicsEligibility::ELIGIBLE) {
            $stats['state'] = $eligibility['state'];
            $this->recordSkip($cursor, $eligibility);

            return $stats;
        }

        try {
            $stats['published'] = $this->publishVehicles($tenant);
            $this->pull($tenant, $cursor, $stats);
            $cursor->forceFill([
                'last_attempted_at' => now(),
                'last_synced_at' => now(),
                'last_status' => $stats['rejected'] > 0 ? self::STATUS_PARTIAL : self::STATUS_OK,
                'last_error' => null,
                'last_error_code' => null,
                'consecutive_failures' => 0,
                'last_stats' => $stats,
            ] + ($stats['received'] > 0 ? ['last_reading_received_at' => now()] : []))->save();
        } catch (Throwable $e) {
            $cursor->forceFill([
                'last_attempted_at' => now(),
                'last_status' => self::STATUS_ERROR,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'last_error_code' => $this->errorCode($e),
                'consecutive_failures' => (int) $cursor->consecutive_failures + 1,
                'last_stats' => $stats,
            ] + ($stats['received'] > 0 ? ['last_reading_received_at' => now()] : []))->save();
            throw $e;
        }

        return $stats;
    }

    /** A skip is not a failure of the feed, but it is written down: "not subscribed" must not look like "no data". */
    private function recordSkip(IntegrationSyncCursor $cursor, array $eligibility): void
    {
        $skip = TelematicsEligibility::isSkip($eligibility['state']);

        $cursor->forceFill([
            'last_attempted_at' => now(),
            'last_status' => $eligibility['state'],
            'last_error' => $skip ? null : $eligibility['message'],
            'last_error_code' => $eligibility['code'],
            'consecutive_failures' => $skip ? 0 : (int) $cursor->consecutive_failures + 1,
        ])->save();
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
            $items = $feed['items'] ?? null;
            $next = $feed['next_cursor'] ?? null;
            if (! is_array($items) || ! is_scalar($next) || ! ctype_digit((string) $next) || (int) $next < (int) $cursor->cursor) {
                throw new OptinexusFeedException('The gateway answered with a feed this application cannot use (items or next_cursor).');
            }

            $received = now();
            foreach ($items as $item) {
                $stats['received']++;
                $this->count($stats, $this->apply($tenant, $item, $received), $item);
            }

            $cursor->forceFill(['cursor' => (int) $next])->save();

            if (count($items) < $limit) {
                return;
            }
        }
    }

    private function apply(Tenant $tenant, mixed $item, CarbonInterface $received): string
    {
        if (! is_array($item)) {
            return VehicleOdometerService::RESULT_REJECTED;
        }

        return $this->odometer->record($tenant->id, $item, $received);
    }

    private function count(array &$stats, string $result, mixed $item): void
    {
        $key = match ($result) {
            VehicleOdometerService::RESULT_APPLIED => 'applied',
            VehicleOdometerService::RESULT_STORED => 'stored',
            VehicleOdometerService::RESULT_HELD => 'held',
            VehicleOdometerService::RESULT_LATE => 'late',
            VehicleOdometerService::RESULT_QUARANTINED => 'quarantined',
            VehicleOdometerService::RESULT_DUPLICATE => 'duplicates',
            VehicleOdometerService::RESULT_REJECTED => 'rejected',
            default => 'unknown',
        };
        $stats[$key]++;

        if ($key === 'rejected' && count($stats['rejected_sample']) < (int) config('optinexus.telematics.rejected_sample')) {
            $reading = is_array($item) && isset($item['reading_id']) && is_string($item['reading_id']) ? mb_substr($item['reading_id'], 0, 64) : null;
            $stats['rejected_sample'][] = ['reading_id' => $reading, 'reason' => is_array($item) ? ($this->odometer->invalidReason($item) ?? 'INVALID_ITEM') : 'INVALID_ITEM'];
        }
    }

    /** A stable English code for the failure: the gateway's own error code, else the HTTP status, else what kind of failure it was. */
    private function errorCode(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $code = $e->response->json('error.code');

            return is_string($code) && preg_match('/^[A-Z0-9_]{2,64}$/', $code) ? $code : 'HTTP_'.$e->response->status();
        }
        if ($e instanceof ConnectionException) {
            return 'CONNECTION_FAILED';
        }
        if ($e instanceof OptinexusFeedException) {
            return OptinexusFeedException::CODE;
        }

        return 'SYNC_FAILED';
    }
}
