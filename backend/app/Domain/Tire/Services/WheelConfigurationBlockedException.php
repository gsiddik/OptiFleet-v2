<?php

namespace App\Domain\Tire\Services;

use RuntimeException;

/**
 * A wheel configuration change was refused because tires are still installed on positions the new
 * configuration would no longer have. Carries the exact position / tire / vehicle per blocker.
 */
class WheelConfigurationBlockedException extends RuntimeException
{
    /** @param  list<array<string, mixed>>  $blockers */
    public function __construct(public readonly array $blockers)
    {
        $details = array_map(
            fn (array $b) => "position {$b['position_code']} has tire {$b['tire_serial_number']} installed on vehicle {$b['vehicle_registration_number']}",
            $blockers,
        );

        parent::__construct('The wheel configuration cannot be changed: '.implode('; ', $details).'. Remove or transfer these tires first.');
    }
}
