<?php

namespace App\Domain\Tire\Support;

/**
 * Tire Operation status — derived, never stored, so it always matches its Work Order:
 *
 *   CANCELLED    the operation was cancelled, or its Work Order was cancelled / rejected
 *   COMPLETED    the Work Order is COMPLETED or CLOSED
 *   IN_PROGRESS  the Work Order is being worked on (IN_PROGRESS, QC_PENDING, ON_HOLD, WAITING_PART, REWORK)
 *   NEW          anything earlier (DRAFT … SCHEDULED): created, work not started
 */
final class TireOperationStatus
{
    public const NEW = 'NEW';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    public const ALL = [self::NEW, self::IN_PROGRESS, self::COMPLETED, self::CANCELLED];

    public const WO_IN_PROGRESS = ['IN_PROGRESS', 'QC_PENDING', 'ON_HOLD', 'WAITING_PART', 'REWORK'];

    public const WO_COMPLETED = ['COMPLETED', 'CLOSED'];

    public const WO_CANCELLED = ['CANCELLED', 'REJECTED'];

    public static function derive(mixed $cancelledAt, ?string $workOrderStatus): string
    {
        return match (true) {
            $cancelledAt !== null, in_array($workOrderStatus, self::WO_CANCELLED, true) => self::CANCELLED,
            in_array($workOrderStatus, self::WO_COMPLETED, true) => self::COMPLETED,
            in_array($workOrderStatus, self::WO_IN_PROGRESS, true) => self::IN_PROGRESS,
            default => self::NEW,
        };
    }

    /** SQL expression of the same rule, for list filtering (operation alias, work order alias). */
    public static function sql(string $op = 'tire_operations', string $wo = 'wo'): string
    {
        $list = fn (array $statuses) => "'".implode("','", $statuses)."'";

        return "CASE WHEN {$op}.cancelled_at IS NOT NULL OR {$wo}.status IN (".$list(self::WO_CANCELLED).") THEN 'CANCELLED'"
            ." WHEN {$wo}.status IN (".$list(self::WO_COMPLETED).") THEN 'COMPLETED'"
            ." WHEN {$wo}.status IN (".$list(self::WO_IN_PROGRESS).") THEN 'IN_PROGRESS' ELSE 'NEW' END";
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, [self::NEW, self::IN_PROGRESS], true);
    }
}
