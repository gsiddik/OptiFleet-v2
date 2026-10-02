<?php

namespace Tests\Feature;

use App\Domain\Tire\Services\WheelConfigurationRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Owner-approved wheel configuration rules (validator + Config Code + position generator).
 * The cases are shared with the frontend preview logic (tests/fixtures/wheel_configuration_cases.json).
 */
class WheelConfigurationRulesTest extends TestCase
{
    private function cases(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/wheel_configuration_cases.json')), true);
    }

    private function input(array $case): array
    {
        return [
            'vehicle_type' => $case['vehicle_type'], 'truck_configuration_type' => $case['truck_configuration_type'],
            'front_axles' => $case['front'], 'rear_axles' => $case['rear'], 'spare_tires' => $case['spare'],
        ];
    }

    public function test_valid_configurations_produce_totals_and_config_code(): void
    {
        foreach ($this->cases()['valid'] as $case) {
            $result = app(WheelConfigurationRules::class)->evaluate($this->input($case));
            $this->assertSame([$case['config_code'], $case['total_axles'], $case['total_wheels']], [$result['config_code'], $result['total_axles'], $result['total_wheels']], $case['name']);
            $axleWheels = (array_sum($case['front']) + array_sum($case['rear'])) * 2;
            $this->assertCount($axleWheels + $case['spare'], $result['positions'], $case['name']);
            $this->assertCount(count(array_unique(array_column($result['positions'], 'position_code'))), $result['positions'], "{$case['name']}: codes are unique");
        }
    }

    public function test_invalid_configurations_are_rejected_on_the_offending_field(): void
    {
        foreach ($this->cases()['invalid'] as $case) {
            try {
                app(WheelConfigurationRules::class)->evaluate($this->input($case));
                $this->fail("{$case['name']} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($case['field'], $e->errors(), $case['name'].': '.json_encode($e->errors()));
            }
        }
    }

    public function test_no_config_code_with_an_empty_group_can_be_produced(): void
    {
        foreach ([[[], [2, 2]], [[2, 2], []], [[], []]] as [$front, $rear]) {
            try {
                app(WheelConfigurationRules::class)->evaluate(['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => $front, 'rear_axles' => $rear, 'spare_tires' => 0]);
                $this->fail('An empty axle group was accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty(array_intersect(['front_axles', 'rear_axles'], array_keys($e->errors())));
            }
        }
    }

    public function test_position_codes_count_from_the_body_and_rear_numbering_restarts(): void
    {
        $positions = app(WheelConfigurationRules::class)->evaluate([
            'vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [2, 2], 'rear_axles' => [2, 2, 2], 'spare_tires' => 3,
        ])['positions'];
        $codes = array_column($positions, 'position_code');

        $this->assertSame(['1FL1', '1FL2', '1FR1', '1FR2'], array_slice($codes, 0, 4));
        $this->assertSame(['1RL1', '1RL2', '1RR1', '1RR2'], array_slice($codes, 8, 4));
        $this->assertNotContains('3RL1', array_slice($codes, 8, 4), 'Rear axles restart at 1, not after the front axles.');
        $this->assertSame(['3RL1', '3RL2', '3RR1', '3RR2'], array_slice($codes, 16, 4));
        $this->assertSame(['S1', 'S2', 'S3'], array_slice($codes, -3));

        $inner = $positions[0];
        $this->assertSame(['FRONT', 1, 1, 'L', 1, 'Front Axle 1 Left Wheel 1'], [$inner['group'], $inner['axle_in_group'], $inner['axle_number'], $inner['side'], $inner['wheel_index'], $inner['label']]);
        $rearFirst = $positions[8];
        $this->assertSame([1, 3], [$rearFirst['axle_in_group'], $rearFirst['axle_number']], 'Rear axle 1 is the 3rd axle overall.');
        $this->assertSame(range(1, count($positions)), array_column($positions, 'sequence'));
        $this->assertSame(['SPARE', null], [end($positions)['group'], end($positions)['side']]);
    }

    public function test_the_prefix_is_a_configuration_indicator_not_axle_data(): void
    {
        $rules = app(WheelConfigurationRules::class);
        $base = ['vehicle_type' => 'TRUCK', 'front_axles' => [2, 2], 'rear_axles' => [2, 2, 2], 'spare_tires' => 0];
        $codes = array_map(fn ($type) => $rules->evaluate($base + ['truck_configuration_type' => $type])['config_code'], ['NON_TRAILER', 'TRAILER', 'SEMI_TRAILER']);

        $this->assertSame(['22.222', '+22.222', '-22.222'], $codes, 'Three distinct configurations, not normalised into one.');
        $trailer = $rules->evaluate($base + ['truck_configuration_type' => 'TRAILER']);
        $this->assertSame([[2, 2], [2, 2, 2], 'TRAILER'], [$trailer['front_axles'], $trailer['rear_axles'], $trailer['truck_configuration_type']]);
        foreach ($trailer['positions'] as $position) {
            $this->assertMatchesRegularExpression('/^(\d[FR][LR]\d|S\d)$/', $position['position_code']);
        }
    }
}
