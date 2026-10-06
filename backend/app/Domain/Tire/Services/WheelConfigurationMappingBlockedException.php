<?php

namespace App\Domain\Tire\Services;

use App\Domain\Shared\Support\Messages;
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
        $lines = array_map(fn (array $b) => Messages::text('tire.labels.vehicleRegistrationNumberPositionCodeTire', [
            'vehicle_registration_number' => $b['vehicle_registration_number'],
            'position_code' => $b['position_code'],
            'tire_serial_number' => $b['tire_serial_number'],
        ]), $blockers);

        parent::__construct(Messages::text('errors.tire.remapBlocked', ['positions' => implode('; ', $lines)]));
    }
}
