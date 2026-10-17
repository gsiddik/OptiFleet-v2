<?php

namespace App\Domain\Integration\Optinexus;

use RuntimeException;

/** The device is under review: its readings are quarantined until a person decides, so it cannot be calibrated meanwhile. */
class TelematicsReviewPending extends RuntimeException
{
    public const CODE = 'TELEMATICS_REVIEW_PENDING';

    public function __construct()
    {
        parent::__construct(self::CODE);
    }
}
