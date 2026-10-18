<?php

namespace App\Domain\Integration\Optinexus;

use RuntimeException;

/** A review decision that cannot be taken as given. `errorCode` is the stable English API code. */
class TelematicsReviewRejected extends RuntimeException
{
    public const INVALID_DECISION = 'TELEMATICS_REVIEW_DECISION_INVALID';

    public const NOTE_REQUIRED = 'TELEMATICS_REVIEW_NOTE_REQUIRED';

    public const NOT_UNDER_REVIEW = 'TELEMATICS_NOT_UNDER_REVIEW';

    public const CALIBRATION_REQUIRED = 'TELEMATICS_CALIBRATION_REQUIRED';

    public const INVALID_ODOMETER = 'ODOMETER_INVALID';

    public const BELOW_CURRENT = 'ODOMETER_BELOW_CURRENT';

    public const REASON_REQUIRED = 'ODOMETER_CORRECTION_REASON_REQUIRED';

    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $errorCode, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($errorCode);
    }

    public static function invalidDecision(): self
    {
        return new self(self::INVALID_DECISION);
    }

    public static function noteRequired(): self
    {
        return new self(self::NOTE_REQUIRED);
    }

    public static function notUnderReview(): self
    {
        return new self(self::NOT_UNDER_REVIEW, 409);
    }

    public static function calibrationRequired(): self
    {
        return new self(self::CALIBRATION_REQUIRED);
    }

    public static function invalidOdometer(): self
    {
        return new self(self::INVALID_ODOMETER);
    }

    public static function belowCurrent(string $current): self
    {
        return new self(self::BELOW_CURRENT, 422, ['current_odometer' => $current]);
    }

    public static function reasonRequired(string $current): self
    {
        return new self(self::REASON_REQUIRED, 422, ['current_odometer' => $current]);
    }
}
