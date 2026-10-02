<?php

namespace App\Domain\Tire\Services;

/**
 * Resolves a vehicle's free-text vehicles.vehicle_type into one of the wheel configuration Vehicle
 * Types. The column has always been free text (seeded/legacy values such as "Car" or "Truck");
 * new values are written as the type codes themselves. Anything unrecognised resolves to null —
 * such a vehicle is never treated as compatible with any configuration.
 */
class VehicleTypeClassifier
{
    private const ALIASES = [
        'CAR' => 'PASSENGER_CAR',
        'PASSENGER' => 'PASSENGER_CAR',
    ];

    public static function resolve(?string $vehicleType): ?string
    {
        if ($vehicleType === null) {
            return null;
        }
        $key = trim((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim($vehicleType))), '_');
        if (in_array($key, WheelConfigurationRules::VEHICLE_TYPES, true)) {
            return $key;
        }

        return self::ALIASES[$key] ?? null;
    }
}
