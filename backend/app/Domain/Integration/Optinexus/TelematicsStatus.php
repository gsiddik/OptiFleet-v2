<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationSyncCursor;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the telematics feed of one tenant is doing, from what was stored (no call to OptiNexus is made to answer this).
 *
 * `state` is exactly one of:
 *   disabled              OptiNexus is not configured in this installation (manual odometer entry is the only source)
 *   not_linked            the tenant is not linked to OptiNexus
 *   not_activated         the tenant has not switched the feed on
 *   pending               switched on, no attempt yet
 *   not_subscribed        OptiNexus says the tenant has no live subscription that includes the telematics application
 *   provider_unavailable  OptiNexus could not confirm the subscription at the last attempt
 *   error                 the gateway refused or failed at the last attempt (`last_error`)
 *   stale                 the last attempt worked but is older than `sync_stale_minutes` (the schedule is not running)
 *   partial               the last attempt worked but some items were unusable and were skipped
 *   ok                    the last attempt worked
 * Nothing here is ever "zero": a device that has not reported has no reading, and a feed that is not running says so.
 */
class TelematicsStatus
{
    /** Pure: the single state for the facts, so the rule can be tested without a database. */
    public static function state(bool $enabled, bool $linked, bool $activated, ?string $lastStatus, ?CarbonInterface $lastAttemptedAt, ?CarbonInterface $lastSyncedAt, int $syncStaleMinutes, CarbonInterface $now): string
    {
        return match (true) {
            ! $enabled => 'disabled',
            ! $linked => 'not_linked',
            ! $activated => 'not_activated',
            $lastAttemptedAt === null => 'pending',
            in_array($lastStatus, ['not_subscribed', 'provider_unavailable', 'error'], true) => $lastStatus,
            $lastSyncedAt === null || $lastSyncedAt->lt($now->copy()->subMinutes($syncStaleMinutes)) => 'stale',
            $lastStatus === OptinexusSyncService::STATUS_PARTIAL => 'partial',
            default => 'ok',
        };
    }

    /** Pure: a device that has reported nothing for this long is "stale" (a label; the data is never hidden). Null = never reported. */
    public static function readingAgeSeconds(?CarbonInterface $measuredAt, CarbonInterface $now): ?int
    {
        return $measuredAt === null ? null : max(0, $now->getTimestamp() - $measuredAt->getTimestamp());
    }

    public static function readingIsStale(?int $ageSeconds, int $staleHours): bool
    {
        return $ageSeconds !== null && $ageSeconds > $staleHours * 3600;
    }

    /**
     * @param  Builder<VehicleTelematicsLink>  $links  the tenant's links in the caller's data scope
     * @return array<string, mixed>
     */
    public function build(Tenant $tenant, Builder $links): array
    {
        $now = now();
        $cursor = IntegrationSyncCursor::query()->withoutGlobalScopes()->where(['tenant_id' => $tenant->id, 'stream' => OptinexusSyncService::STREAM])->first();
        $activated = $tenant->telematics_activated_at !== null;
        $staleMinutes = (int) config('optinexus.telematics.sync_stale_minutes');
        $staleHours = (int) config('optinexus.telematics.reading_stale_hours');

        $state = self::state(
            (bool) config('optinexus.enabled'), (bool) $tenant->optinexus_tenant_id, $activated,
            $cursor?->last_status, $cursor?->last_attempted_at, $cursor?->last_synced_at, $staleMinutes, $now,
        );

        $total = (clone $links)->count();
        $withReadings = (clone $links)->whereNotNull('last_measured_at')->count();
        $staleReadings = (clone $links)->whereNotNull('last_measured_at')->where('last_measured_at', '<', $now->copy()->subHours($staleHours))->count();

        return [
            'integration' => [
                'enabled' => (bool) config('optinexus.enabled'),
                'linked' => (bool) $tenant->optinexus_tenant_id,
                'activated' => $activated,
                'activated_at' => $tenant->telematics_activated_at?->toIso8601String(),
                'state' => $state,
                'last_attempted_at' => $cursor?->last_attempted_at?->toIso8601String(),
                'last_synced_at' => $cursor?->last_synced_at?->toIso8601String(),
                'last_reading_received_at' => $cursor?->last_reading_received_at?->toIso8601String(),
                'last_error' => $cursor && $cursor->last_error_code ? ['code' => $cursor->last_error_code, 'message' => $cursor->last_error] : null,
                'consecutive_failures' => (int) ($cursor?->consecutive_failures ?? 0),
                'sync_stale_after_minutes' => $staleMinutes,
                'last_stats' => $cursor?->last_stats,
            ],
            'devices' => [
                'total' => $total,
                'with_readings' => $withReadings,
                'without_readings' => $total - $withReadings,
                'stale' => $staleReadings,
                'stale_after_hours' => $staleHours,
                'under_review' => (clone $links)->where('status', VehicleTelematicsLink::STATUS_REVIEW)->count(),
                'needs_calibration' => (clone $links)->needsCalibration()->count(),
            ],
            'quarantined_last_7_days' => VehicleOdometerReading::query()->where('tenant_id', $tenant->id)->where('disposition', VehicleOdometerReading::DISPOSITION_QUARANTINED)
                ->where('received_at', '>=', $now->copy()->subDays(7))->count(),
        ];
    }
}
