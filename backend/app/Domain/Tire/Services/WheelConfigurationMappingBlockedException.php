<?php

namespace App\Domain\Tire\Services;

use RuntimeException;

/**
 * A vehicle mapping change was refused because active tires sit on positions the target
 * configuration version does not have. Carries vehicle / position / tire per conflict.
 */
class WheelConfigurationMappingBlockedException extends RuntimeException
{
    /** @param  list<array<string, string>>  $blockers */
    public function __construct(public readonly array $blockers)
    {
        $lines = array_map(fn (array $b) => "{$b['vehicle_registration_number']} {$b['position_code']} — Tire {$b['tire_serial_number']}", $blockers);

        parent::__construct('Vehicle cannot be remapped because active tires are installed on positions removed by the target configuration: '
            .implode('; ', $lines).'. Remove or transfer these tires first.');
    }
}
