<?php

namespace Tests\Unit;

use App\Domain\Tire\Inspection\UsedTireDecisionEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pass/fail decision engine: SCRAP for confirmed rejections, HOLD for anything incomplete or
 * uncertain, then the retread / repair / reuse paths — all thresholds from the rule profile.
 */
class UsedTireDecisionEngineTest extends TestCase
{
    private UsedTireDecisionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new UsedTireDecisionEngine;
    }

    /** A truck / bus profile — test values only, the engine itself holds none. */
    private function profile(array $overrides = []): array
    {
        return array_replace_recursive([
            'profile_id' => 'p1', 'version' => 3, 'tire_category' => 'TRUCK_BUS',
            'd_service_mm' => '3.00', 'd_pull_mm' => '5.00', 'a_max_months' => 120, 'a_retread_max_months' => 84, 'n_retread_max' => 2,
            'repair_limits' => [
                'allowed_locations' => ['TREAD', 'SHOULDER'], 'max_puncture_diameter_mm' => '10', 'max_cut_length_mm' => '25',
                'max_cut_width_mm' => '5', 'max_cut_depth_mm' => '8', 'max_repairs' => 2,
                'allow_overlap_previous_repair' => false, 'allow_reinforcement_damage' => false,
            ],
            'application_limits' => [],
        ], $overrides);
    }

    /** Every question answered "good". */
    private function goodAnswers(array $overrides = []): array
    {
        return array_merge([
            'identity_status' => 'COMPLETE', 'internal_inspected' => 'YES', 'wear_pattern' => 'EVEN', 'bulge_separation' => 'NONE',
            'cord_exposure' => 'NONE', 'sidewall_condition' => 'NORMAL', 'bead_condition' => 'NORMAL', 'inner_liner_condition' => 'NORMAL',
            'run_flat_overheat' => 'NO', 'leak_foreign_object' => 'NO', 'previous_repair' => 'NONE', 'age_chemical' => 'NONE',
            'casing_compliance' => 'MEETS', 'repair_eligibility' => null, 'specialist_result' => null,
        ], $overrides);
    }

    /** 3 zones × inner / outer main groove; $min is the most worn point. */
    private function measurements(string $min = '9.0'): array
    {
        $points = [];
        foreach ([1, 2, 3] as $zone) {
            foreach (['INNER_MAIN', 'OUTER_MAIN'] as $groove) {
                $points[] = ['zone' => $zone, 'groove' => $groove, 'depth_mm' => $zone === 2 && $groove === 'OUTER_MAIN' ? $min : '11.5'];
            }
        }

        return $points;
    }

    /** Every point at the same depth. */
    private function uniform(string $depth): array
    {
        return array_map(fn ($m) => ['depth_mm' => $depth] + $m, $this->measurements());
    }

    private function puncture(array $overrides = []): array
    {
        return array_merge(['location' => 'TREAD', 'damage_type' => 'PUNCTURE', 'diameter_mm' => '6', 'reaches_reinforcement' => 'NO', 'overlaps_previous_repair' => 'NO'], $overrides);
    }

    private function decide(array $answers, array $measurements, array $damages = [], ?array $profile = null, array $facts = [], ?string $dNew = null): array
    {
        return $this->engine->evaluate($answers, $measurements, $damages, $profile ?? $this->profile(),
            array_merge(['category' => 'TRUCK_BUS', 'age_months' => 36, 'retread_count' => 0], $facts), $dNew);
    }

    public function test_complete_inspection_without_issues_is_reuse(): void
    {
        $r = $this->decide($this->goodAnswers(), $this->measurements('9.0'));
        $this->assertSame('REUSE', $r['recommendation']);
        $this->assertSame(['C' => true, 'X' => false, 'R' => false, 'P' => null, 'T' => false, 'K' => true, 'F' => false], $r['variables']);
        $this->assertSame('9.00', $r['d_min_mm']);
    }

    public function test_any_unknown_not_inspected_or_cannot_confirm_answer_is_hold(): void
    {
        foreach ([
            ['identity_status' => 'PARTIALLY_UNKNOWN'], ['internal_inspected' => 'NOT_YET'], ['wear_pattern' => 'NOT_INSPECTED'],
            ['bulge_separation' => 'SUSPECTED'], ['cord_exposure' => 'NOT_INSPECTED'], ['sidewall_condition' => 'NOT_INSPECTED'],
            ['bead_condition' => 'NOT_INSPECTED'], ['inner_liner_condition' => 'NOT_INSPECTED'], ['run_flat_overheat' => 'UNKNOWN'],
            ['leak_foreign_object' => 'NOT_TESTED'], ['previous_repair' => 'QUESTIONABLE'], ['age_chemical' => 'SUSPECTED'],
            ['casing_compliance' => 'CANNOT_CONFIRM'], ['wear_pattern' => null],
        ] as $answer) {
            $r = $this->decide($this->goodAnswers($answer), $this->measurements());
            $this->assertSame('HOLD', $r['recommendation'], json_encode($answer));
            $this->assertFalse($r['variables']['C']);
            $this->assertNotEmpty($r['reasons']);
        }
    }

    public function test_interior_not_inspected_never_yields_reuse_and_explains_why(): void
    {
        $r = $this->decide($this->goodAnswers(['internal_inspected' => 'NOT_YET']), $this->measurements());
        $this->assertSame('HOLD', $r['recommendation']);
        $this->assertSame(['Interior not inspected after removal from the rim.'], $r['reasons']);
    }

    public function test_fewer_than_six_points_missing_profile_or_unknown_age_is_hold(): void
    {
        $five = array_slice($this->measurements(), 0, 5);
        $this->assertSame('HOLD', $this->decide($this->goodAnswers(), $five)['recommendation']);
        $this->assertNull($this->decide($this->goodAnswers(), $five)['d_min_mm']);

        $noProfile = $this->engine->evaluate($this->goodAnswers(), $this->measurements(), [], null, ['category' => 'TRUCK_BUS', 'age_months' => 36, 'retread_count' => 0]);
        $this->assertSame('HOLD', $noProfile['recommendation']);
        $this->assertStringContainsString('No active inspection rule profile', $noProfile['reasons'][0]);

        $this->assertSame('HOLD', $this->decide($this->goodAnswers(), $this->measurements(), [], null, ['age_months' => null])['recommendation']);
    }

    public function test_confirmed_structural_damage_is_scrap_even_when_other_answers_are_incomplete(): void
    {
        foreach ([
            ['bulge_separation' => 'PRESENT'], ['cord_exposure' => 'PRESENT'], ['sidewall_condition' => 'DEEP_CUT_CRACK'],
            ['bead_condition' => 'BEAD_WIRE_DAMAGED'], ['inner_liner_condition' => 'WRINKLED_HEAT_DAMAGE'], ['run_flat_overheat' => 'PHYSICAL_SIGN'],
            ['previous_repair' => 'DOES_NOT_MEET'], ['age_chemical' => 'DEGRADED'],
        ] as $answer) {
            $r = $this->decide($this->goodAnswers($answer + ['wear_pattern' => 'NOT_INSPECTED']), []);
            $this->assertSame('SCRAP', $r['recommendation'], json_encode($answer));
            $this->assertTrue($r['variables']['X']);
        }
        $old = $this->decide($this->goodAnswers(), $this->measurements(), [], null, ['age_months' => 121]);
        $this->assertSame('SCRAP', $old['recommendation']);
        $this->assertStringContainsString('A_max', $old['reasons'][0]);
    }

    public function test_repairable_damage_with_usable_tread_is_repair(): void
    {
        $r = $this->decide($this->goodAnswers(['leak_foreign_object' => 'YES', 'repair_eligibility' => 'YES']), $this->measurements('7.0'), [$this->puncture()]);
        $this->assertSame('REPAIR', $r['recommendation']);
        $this->assertSame([true, true, false], [$r['variables']['R'], $r['variables']['P'], $r['variables']['T']]);
    }

    public function test_repair_eligibility_unclear_is_hold_and_beyond_limits_is_scrap(): void
    {
        $answers = $this->goodAnswers(['leak_foreign_object' => 'YES']);
        $this->assertSame('HOLD', $this->decide($answers + ['repair_eligibility' => 'SPECIALIST_REQUIRED'], $this->measurements('7.0'), [$this->puncture()])['recommendation']);
        $this->assertSame('HOLD', $this->decide(array_merge($answers, ['repair_eligibility' => 'SPECIALIST_REQUIRED']), $this->measurements('7.0'), [$this->puncture()])['recommendation']);
        $this->assertSame('REPAIR', $this->decide(array_merge($answers, ['repair_eligibility' => 'SPECIALIST_REQUIRED', 'specialist_result' => 'ACCEPTED']), $this->measurements('7.0'), [$this->puncture()])['recommendation']);
        $this->assertSame('SCRAP', $this->decide(array_merge($answers, ['repair_eligibility' => 'NO']), $this->measurements('7.0'), [$this->puncture()])['recommendation']);
        $this->assertSame('HOLD', $this->decide(array_merge($answers, ['repair_eligibility' => 'YES']), $this->measurements('7.0'), [$this->puncture(['reaches_reinforcement' => 'UNKNOWN'])])['recommendation']);

        $tooBig = $this->decide(array_merge($answers, ['repair_eligibility' => 'YES']), $this->measurements('7.0'), [$this->puncture(['diameter_mm' => '12'])]);
        $this->assertSame('SCRAP', $tooBig['recommendation']);
        $this->assertStringContainsString('exceeds the limit of 10 mm', $tooBig['reasons'][0]);
        $this->assertSame('SCRAP', $this->decide(array_merge($answers, ['repair_eligibility' => 'YES']), $this->measurements('7.0'), [$this->puncture(['location' => 'SIDEWALL'])])['recommendation']);
        $this->assertSame('SCRAP', $this->decide(array_merge($answers, ['repair_eligibility' => 'YES']), $this->measurements('7.0'), [$this->puncture(), $this->puncture(), $this->puncture()])['recommendation']);
        // A leak with no damage recorded cannot be decided.
        $this->assertSame('HOLD', $this->decide($answers, $this->measurements('7.0'))['recommendation']);
    }

    public function test_tread_needing_replacement_is_retread_or_hold_retread_candidate(): void
    {
        $candidate = $this->decide($this->goodAnswers(), $this->measurements('4.5'));
        $this->assertSame(['HOLD', 'RETREAD_CANDIDATE'], [$candidate['recommendation'], $candidate['recommendation_detail']]);
        $this->assertTrue($candidate['variables']['T']);

        $retread = $this->decide($this->goodAnswers(['specialist_result' => 'ACCEPTED']), $this->measurements('4.5'));
        $this->assertSame(['RETREAD', null], [$retread['recommendation'], $retread['additional_work']]);

        $this->assertSame('SCRAP', $this->decide($this->goodAnswers(['specialist_result' => 'REJECTED']), $this->measurements('4.5'))['recommendation']);
        // Unsuitable wear pattern triggers T even with deep tread.
        $this->assertSame('RETREAD_CANDIDATE', $this->decide($this->goodAnswers(['wear_pattern' => 'FLAT_SPOT']), $this->measurements('12'))['recommendation_detail']);
    }

    public function test_casing_that_fails_retread_requirements_is_scrap(): void
    {
        $this->assertSame('SCRAP', $this->decide($this->goodAnswers(['specialist_result' => 'ACCEPTED']), $this->measurements('4.5'), [], null, ['retread_count' => 2])['recommendation']);
        $this->assertSame('SCRAP', $this->decide($this->goodAnswers(['specialist_result' => 'ACCEPTED']), $this->measurements('4.5'), [], null, ['age_months' => 90])['recommendation']);
        $this->assertSame('SCRAP', $this->decide($this->goodAnswers(['casing_compliance' => 'DOES_NOT_MEET']), $this->measurements('4.5'))['recommendation']);
        // The same casing age is fine when the tread is still usable (A_retread_max only governs retreading).
        $this->assertSame('REUSE', $this->decide($this->goodAnswers(), $this->measurements('9'), [], null, ['age_months' => 90])['recommendation']);
    }

    public function test_repair_and_retread_coexist_as_retread_with_casing_repair(): void
    {
        $r = $this->decide($this->goodAnswers(['leak_foreign_object' => 'YES', 'repair_eligibility' => 'YES', 'specialist_result' => 'ACCEPTED']), $this->measurements('4.5'), [$this->puncture()]);
        $this->assertSame(['RETREAD', 'CASING_REPAIR'], [$r['recommendation'], $r['additional_work']]);
    }

    public function test_category_profiles_are_not_mixed(): void
    {
        // 4.5 mm is above a passenger profile's D_pull (3.0) but below the truck profile's (5.0).
        $passenger = $this->profile(['tire_category' => 'PASSENGER_LT', 'd_service_mm' => '1.60', 'd_pull_mm' => '3.00']);
        $this->assertSame('REUSE', $this->decide($this->goodAnswers(), $this->measurements('4.5'), [], $passenger, ['category' => 'PASSENGER_LT'])['recommendation']);
        $this->assertSame('HOLD', $this->decide($this->goodAnswers(), $this->measurements('4.5'))['recommendation']);
    }

    public function test_uneven_wear_adds_vehicle_follow_up(): void
    {
        $r = $this->decide($this->goodAnswers(['wear_pattern' => 'ONE_SIDED']), $this->measurements());
        $this->assertSame('REUSE', $r['recommendation']);
        $this->assertStringContainsString('alignment', $r['follow_ups'][0]);
    }

    public function test_remaining_tread_percent_formula_and_it_never_overrides_the_decision(): void
    {
        // D_new 16, D_min 7, D_service 3 → (7 − 3) / (16 − 3) × 100 = 30.77 %
        $r = $this->decide($this->goodAnswers(), $this->measurements('7.0'), [], null, [], '16');
        $this->assertSame('30.77', $r['remaining_tread_percent']);
        $this->assertSame('100.00', $this->decide($this->goodAnswers(), $this->uniform('20'), [], null, [], '16')['remaining_tread_percent']);
        $this->assertSame('0.00', $this->decide($this->goodAnswers(), $this->measurements('2'), [], null, [], '16')['remaining_tread_percent']);

        // 80 % tread left but structural damage → still SCRAP.
        $damaged = $this->decide($this->goodAnswers(['bulge_separation' => 'PRESENT']), $this->uniform('13.4'), [], null, [], '16');
        $this->assertSame(['SCRAP', '80.00'], [$damaged['recommendation'], $damaged['remaining_tread_percent']]);
    }
}
