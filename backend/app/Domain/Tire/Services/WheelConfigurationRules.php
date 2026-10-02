<?php

namespace App\Domain\Tire\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Wheel configuration business rules — the backend authority behind "New Wheels Configuration"
 * (the frontend's wheel-configuration/wheelLayout.ts + vehicleTypes.ts mirror them for the
 * real-time preview; tests/fixtures/wheel_configuration_cases.json keeps both in step).
 *
 *   Configuration Validator → Position Generator → generated wheel positions
 *
 * Owner rules: wheels per side 1–4 (one Config Code digit per axle); 0–6 axles per group while
 * editing but at least one front AND one rear axle to be valid; spare tires 0–4. Config Code =
 * <prefix><front digits>.<rear digits>, prefix "+" Truck · Trailer, "-" Truck · Semi Trailer,
 * none otherwise — the prefix is a configuration-type indicator, never axle data. Position code =
 * <axle number in its group><F|R><L|R><wheel index from the body outwards> (rear restarts at 1);
 * spare tires S1…Sn.
 */
class WheelConfigurationRules
{
    public const VEHICLE_TYPES = ['PASSENGER_CAR', 'TRUCK', 'BUS', 'FORKLIFT', 'VAN', 'HEAVY_EQUIPMENT'];

    /** Truck Configuration Type => Config Code prefix */
    public const TRUCK_CONFIGURATION_TYPES = ['NON_TRAILER' => '', 'TRAILER' => '+', 'SEMI_TRAILER' => '-'];

    public const MAX_AXLES_PER_GROUP = 6;

    public const MIN_WHEELS_PER_SIDE = 1;

    public const MAX_WHEELS_PER_SIDE = 4;

    public const MAX_SPARE_TIRES = 4;

    /**
     * Validates a configuration and returns it normalised with everything derived from it.
     * front_axles / rear_axles hold the wheels per side of each axle, front-most first.
     *
     * @param  array{vehicle_type?: mixed, truck_configuration_type?: mixed, front_axles?: mixed, rear_axles?: mixed, spare_tires?: mixed}  $input
     * @return array{vehicle_type: string, truck_configuration_type: ?string, front_axles: list<int>, rear_axles: list<int>, spare_tires: int, total_axles: int, total_wheels: int, config_code: string, positions: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function evaluate(array $input): array
    {
        $wheels = ['required', 'integer', 'min:'.self::MIN_WHEELS_PER_SIDE, 'max:'.self::MAX_WHEELS_PER_SIDE];
        $validated = Validator::make($input, [
            'vehicle_type' => ['required', Rule::in(self::VEHICLE_TYPES)],
            'truck_configuration_type' => ['nullable', 'required_if:vehicle_type,TRUCK', 'prohibited_unless:vehicle_type,TRUCK', Rule::in(array_keys(self::TRUCK_CONFIGURATION_TYPES))],
            // min:1 — a configuration needs at least one front and one rear axle.
            'front_axles' => ['required', 'array', 'min:1', 'max:'.self::MAX_AXLES_PER_GROUP],
            'front_axles.*' => $wheels,
            'rear_axles' => ['required', 'array', 'min:1', 'max:'.self::MAX_AXLES_PER_GROUP],
            'rear_axles.*' => $wheels,
            'spare_tires' => ['required', 'integer', 'min:0', 'max:'.self::MAX_SPARE_TIRES],
        ], [
            'front_axles.required' => 'At least one front axle is required.',
            'front_axles.min' => 'At least one front axle is required.',
            'rear_axles.required' => 'At least one rear axle is required.',
            'rear_axles.min' => 'At least one rear axle is required.',
        ])->validate();

        $front = array_map('intval', array_values($validated['front_axles']));
        $rear = array_map('intval', array_values($validated['rear_axles']));
        $spare = (int) $validated['spare_tires'];
        $truckType = $validated['vehicle_type'] === 'TRUCK' ? $validated['truck_configuration_type'] : null;

        return [
            'vehicle_type' => $validated['vehicle_type'],
            'truck_configuration_type' => $truckType,
            'front_axles' => $front,
            'rear_axles' => $rear,
            'spare_tires' => $spare,
            'total_axles' => count($front) + count($rear),
            'total_wheels' => (array_sum($front) + array_sum($rear)) * 2 + $spare,
            'config_code' => self::configCode($front, $rear, $truckType),
            'positions' => self::positions($front, $rear, $spare),
        ];
    }

    /** "22.222", "+12.221", "-22.222"; both groups must be non-empty (validated above). */
    public static function configCode(array $front, array $rear, ?string $truckConfigurationType = null): string
    {
        $prefix = self::TRUCK_CONFIGURATION_TYPES[$truckConfigurationType ?? ''] ?? '';

        return $prefix.implode('', $front).'.'.implode('', $rear);
    }

    /**
     * Every position the configuration defines, in display order: front axles, rear axles (each
     * left then right, wheel 1 = closest to the body), then spare tires.
     *
     * @return list<array{position_code: string, group: string, axle_in_group: ?int, axle_number: ?int, side: ?string, wheel_index: ?int, label: string, sequence: int}>
     */
    public static function positions(array $front, array $rear, int $spareTires): array
    {
        $positions = [];
        $sequence = 0;
        $axleNumber = 0;
        foreach (['F' => $front, 'R' => $rear] as $group => $axles) {
            foreach (array_values($axles) as $i => $perSide) {
                $axleNumber++;
                foreach (['L' => 'Left', 'R' => 'Right'] as $side => $sideName) {
                    for ($wheel = 1; $wheel <= $perSide; $wheel++) {
                        $positions[] = [
                            'position_code' => ($i + 1).$group.$side.$wheel,
                            'group' => $group === 'F' ? 'FRONT' : 'REAR',
                            'axle_in_group' => $i + 1,
                            'axle_number' => $axleNumber,
                            'side' => $side,
                            'wheel_index' => $wheel,
                            'label' => ($group === 'F' ? 'Front' : 'Rear').' Axle '.($i + 1)." {$sideName} Wheel {$wheel}",
                            'sequence' => ++$sequence,
                        ];
                    }
                }
            }
        }
        for ($s = 1; $s <= $spareTires; $s++) {
            $positions[] = ['position_code' => "S{$s}", 'group' => 'SPARE', 'axle_in_group' => null, 'axle_number' => null, 'side' => null, 'wheel_index' => null, 'label' => "Spare Tire {$s}", 'sequence' => ++$sequence];
        }

        return $positions;
    }
}
