<?php

namespace App\Domain\Vehicle\Services;

use RuntimeException;

/**
 * An odometer value the vehicle record cannot take as it was offered. `errorCode` is the stable English API code; `status` the HTTP
 * status the controllers answer with; `context` carries what the person needs to decide (never other tenants' data).
 */
class OdometerRejected extends RuntimeException
{
    public const INVALID = 'ODOMETER_INVALID';

    public const FUTURE_TIME = 'ODOMETER_FUTURE_TIME';

    public const BELOW_CURRENT = 'ODOMETER_BELOW_CURRENT';

    public const TIME_CONFLICT = 'ODOMETER_TIME_CONFLICT';

    public const IDEMPOTENCY_CONFLICT = 'ODOMETER_IDEMPOTENCY_CONFLICT';

    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $errorCode, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($errorCode);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function futureTime(): self
    {
        return new self(self::FUTURE_TIME);
    }

    public static function belowCurrent(string $current): self
    {
        return new self(self::BELOW_CURRENT, 422, ['current_odometer' => $current]);
    }

    public static function timeConflict(string $current, ?string $measuredAt): self
    {
        return new self(self::TIME_CONFLICT, 422, ['current_odometer' => $current, 'current_measured_at' => $measuredAt]);
    }

    public static function idempotencyConflict(): self
    {
        return new self(self::IDEMPOTENCY_CONFLICT, 409);
    }
}
