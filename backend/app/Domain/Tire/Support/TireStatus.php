<?php

namespace App\Domain\Tire\Support;

/**
 * Physical tire statuses and what they mean for stock. Status visibility is not availability:
 * Used Stocks shows every used, non-terminal tire, but only new stock and REUSE can be installed.
 *
 *   IN_STOCK / RESERVED  new stock, never installed            → available
 *   REUSE                used, inspected and fit for reuse      → available (reusable used stock)
 *   REMOVED              taken off a vehicle, awaiting inspection → not available
 *   HOLD                 inspection incomplete / decision pending → not available
 *   REPAIR / RETREAD     in the repair / retread lifecycle       → not available
 *   UNDER_INSPECTION / QUARANTINED  repair/retread cycle states (legacy QUARANTINED) → not available
 *   SCRAPPED (shown "SCRAP") / SOLD / LOST  terminal             → not stock
 */
final class TireStatus
{
    public const REUSE = 'REUSE';

    public const HOLD = 'HOLD';

    public const REMOVED = 'REMOVED';

    public const SCRAPPED = 'SCRAPPED';

    /** Statuses a tire can be installed from. */
    public const AVAILABLE_FOR_INSTALLATION = ['IN_STOCK', 'RESERVED', self::REUSE];

    /** Statuses a used-tire inspection can start from (awaiting inspection / disposition). */
    public const AWAITING_INSPECTION = [self::REMOVED, self::HOLD];

    private function __construct() {}
}
