<?php

namespace App\Domain\Tire\Inspection;

use App\Domain\Shared\Support\Messages;

/**
 * Pass/fail decision for a used tire inspection — no weighted scoring. Every threshold comes from
 * the tire's rule profile (category + optional model + application); nothing is hardcoded here, and
 * without a profile the result is HOLD.
 *
 * Variables:
 *   C  required inspection / data complete (no unknown, not-inspected, suspected or pending item)
 *   X  a confirmed final rejection reason exists
 *   R  damage requiring repair exists
 *   P  every damage is within the permitted repair limits (and the repair is eligible)
 *   T  tread replacement required:  D_min <= D_pull, or the tread is otherwise unsuitable to retain
 *   K  casing qualifies for retread (compliance, A_retread_max, N_retread_max)
 *   F  specialist / retreader result finalized (accepted)
 *
 * Decision order:
 *   X                       → SCRAP
 *   not C                   → HOLD
 *   T and not K             → SCRAP   (casing fails retread requirements)
 *   T and R and not P       → SCRAP   (casing damage beyond repair limits)
 *   T and F                 → RETREAD (+ CASING_REPAIR when R)
 *   T                       → HOLD — RETREAD CANDIDATE (awaiting the retreader's casing result)
 *   R and P                 → REPAIR
 *   R and not P             → SCRAP
 *   otherwise               → REUSE
 *
 * Remaining tread % is informational only and never changes the decision.
 */
final class UsedTireDecisionEngine
{
    /** Bead / inner liner condition codes → message keys for their display text (no reformatting of the code). */
    private const CONDITION_LABEL_KEYS = [
        'TORN' => 'tire.conditions.torn',
        'DEFORMED' => 'tire.conditions.deformed',
        'BEAD_WIRE_DAMAGED' => 'tire.conditions.beadWireDamaged',
        'CRACKED_DELAMINATED' => 'tire.conditions.crackedDelaminated',
        'WRINKLED_HEAT_DAMAGE' => 'tire.conditions.wrinkledHeatDamage',
        'CORD_EXPOSED' => 'tire.conditions.cordExposed',
    ];

    public const REUSE = 'REUSE';

    public const REPAIR = 'REPAIR';

    public const RETREAD = 'RETREAD';

    public const SCRAP = 'SCRAP';

    public const HOLD = 'HOLD';

    public const MIN_MEASUREMENT_ZONES = 3;

    /** Wear patterns that make the tread unsuitable to keep regardless of depth (→ label message key). */
    private const UNSUITABLE_WEAR = ['CUPPING_SCALLOPING' => 'tire.wearPatterns.cuppingScalloping', 'FLAT_SPOT' => 'tire.wearPatterns.flatSpot'];

    /** Uneven wear → vehicle-side follow-up message key (the tire repair does not fix the root cause). */
    private const WEAR_FOLLOW_UPS = [
        'ONE_SIDED' => 'tire.reasons.oneSidedWearCheckWheelAlignment',
        'CENTER' => 'tire.reasons.centerWearCheckTirePressureOver',
        'BOTH_SIDES' => 'tire.reasons.bothSidesWearCheckTirePressure',
        'CUPPING_SCALLOPING' => 'tire.reasons.cuppingScallopingCheckSuspensionShockAbsorbers',
        'FLAT_SPOT' => 'tire.reasons.flatSpotCheckBrakesWheelLock',
    ];

    /** Damage type / location / measured field codes → label message keys. */
    private const DAMAGE_TYPE_KEYS = [
        'PUNCTURE' => 'tire.damageTypes.puncture', 'CUT' => 'tire.damageTypes.cut', 'CRACK' => 'tire.damageTypes.crack',
        'ABRASION' => 'tire.damageTypes.abrasion', 'SEPARATION' => 'tire.damageTypes.separation',
        'PREVIOUS_REPAIR_DAMAGE' => 'tire.damageTypes.previousRepairDamage', 'OTHER' => 'tire.damageTypes.other',
    ];

    private const DAMAGE_LOCATION_KEYS = [
        'TREAD' => 'tire.damageLocations.tread', 'SHOULDER' => 'tire.damageLocations.shoulder', 'SIDEWALL' => 'tire.damageLocations.sidewall',
        'BEAD' => 'tire.damageLocations.bead', 'INNER_LINER' => 'tire.damageLocations.innerLiner',
    ];

    private const DAMAGE_FIELD_KEYS = [
        'diameter_mm' => 'tire.damageFields.diameter', 'length_mm' => 'tire.damageFields.length',
        'width_mm' => 'tire.damageFields.width', 'depth_mm' => 'tire.damageFields.depth',
    ];

    private const NOT_KNOWN = ['NOT_INSPECTED', 'UNKNOWN', 'NOT_TESTED', 'CANNOT_CONFIRM', 'SUSPECTED', 'QUESTIONABLE', 'NOT_YET', 'PARTIALLY_UNKNOWN', 'CANNOT_VERIFY'];

    /**
     * @param  array<string, ?string>  $answers  questionnaire codes (see TireUsedInspection::ANSWERS)
     * @param  list<array{zone: int, groove: string, depth_mm: string}>  $measurements
     * @param  list<array<string, mixed>>  $damages
     * @param  array<string, mixed>|null  $profile  TireRuleProfile::snapshot()
     * @param  array{category: ?string, age_months: ?int, retread_count: int}  $facts
     */
    public function evaluate(array $answers, array $measurements, array $damages, ?array $profile, array $facts, ?string $dNew = null): array
    {
        $scrap = [];   // X reasons
        $hold = [];    // not-C reasons
        $repairFails = [];
        $followUps = [];
        $a = fn (string $key) => $answers[$key] ?? null;

        // ---- profile / category -------------------------------------------------------------
        if ($facts['category'] === null) {
            $hold[] = Messages::make('tire.reasons.tireCategoryUnknownSetVehicleGroup');
        }
        if ($profile === null) {
            $hold[] = Messages::make('tire.reasons.noActiveInspectionRuleProfileTire');
        }

        // ---- Q1 / Q2 / unknown answers --------------------------------------------------------
        $questions = [
            'identity_status' => 'tire.reasons.tireIdentityCategoryManufactureDateNot',
            'internal_inspected' => 'tire.reasons.interiorNotInspectedAfterRemovalRim',
            'wear_pattern' => 'tire.reasons.wearPatternNotInspected',
            'bulge_separation' => 'tire.reasons.bulgeDeformationSeparationSuspectedNotInspected',
            'cord_exposure' => 'tire.reasons.cordWireExposureSuspectedNotInspected',
            'sidewall_condition' => 'tire.reasons.sidewallNotInspected',
            'bead_condition' => 'tire.reasons.beadNotInspected',
            'inner_liner_condition' => 'tire.reasons.innerLinerNotInspected',
            'run_flat_overheat' => 'tire.reasons.runFlatLowPressureOverheatHistory',
            'leak_foreign_object' => 'tire.reasons.leakForeignObjectNotTested',
            'previous_repair' => 'tire.reasons.previousRepairQuestionableNotInspected',
            'age_chemical' => 'tire.reasons.ageChemicalDamageSuspectedNotInspected',
            'casing_compliance' => 'tire.reasons.ageRetreadCasingComplianceCannotYet',
        ];
        foreach ($questions as $key => $labelKey) {
            $value = $a($key);
            if ($value === null || in_array($value, self::NOT_KNOWN, true)) {
                $hold[] = Messages::make($value === null ? 'tire.reasons.labelNotAnswered' : 'tire.reasons.openItem', ['label' => Messages::make($labelKey)]);
            }
        }

        // ---- confirmed rejections (X) ---------------------------------------------------------
        if ($a('bulge_separation') === 'PRESENT') {
            $scrap[] = Messages::make('tire.reasons.confirmedBulgeDeformationSeparation');
        }
        if ($a('cord_exposure') === 'PRESENT') {
            $scrap[] = Messages::make('tire.reasons.cordWireExposed');
        }
        if ($a('sidewall_condition') === 'DEEP_CUT_CRACK') {
            $scrap[] = Messages::make('tire.reasons.deepSidewallCutCrack');
        }
        if (in_array($a('bead_condition'), ['TORN', 'DEFORMED', 'BEAD_WIRE_DAMAGED'], true)) {
            $scrap[] = Messages::make('tire.reasons.beadDamageDetail', ['condition' => Messages::make(self::CONDITION_LABEL_KEYS[$a('bead_condition')])]);
        }
        if (in_array($a('inner_liner_condition'), ['CRACKED_DELAMINATED', 'WRINKLED_HEAT_DAMAGE', 'CORD_EXPOSED'], true)) {
            $scrap[] = Messages::make('tire.reasons.innerLinerFailure', ['condition' => Messages::make(self::CONDITION_LABEL_KEYS[$a('inner_liner_condition')])]);
        }
        if ($a('run_flat_overheat') === 'PHYSICAL_SIGN') {
            $scrap[] = Messages::make('tire.reasons.physicalSignRunFlatLowPressure');
        }
        if ($a('previous_repair') === 'DOES_NOT_MEET') {
            $scrap[] = Messages::make('tire.reasons.previousRepairDoesNotMeetStandard');
        }
        if ($a('age_chemical') === 'DEGRADED') {
            $scrap[] = Messages::make('tire.reasons.permanentChemicalAgeDegradationHardenedBrittle');
        }

        // ---- age --------------------------------------------------------------------------------
        $age = $facts['age_months'];
        if ($age === null) {
            $hold[] = Messages::make('tire.reasons.tireAgeUnknownManufactureDateCode');
        } elseif ($profile !== null && $age > (int) $profile['a_max_months']) {
            $scrap[] = Messages::make('tire.reasons.tireAgeAgeMonthsExceedsMaximum', ['age' => $age, 'a_max_months' => $profile['a_max_months']]);
        }

        // ---- tread ------------------------------------------------------------------------------
        $dMin = $this->minimumDepth($measurements, $hold);
        $tread = null;
        $treadReasons = [];
        if ($dMin !== null && $profile !== null) {
            $dPull = $this->hundredths((string) $profile['d_pull_mm']);
            $dService = $this->hundredths((string) $profile['d_service_mm']);
            $tread = $dMin <= $dPull;
            if ($dMin < $dService) {
                $treadReasons[] = Messages::make('tire.reasons.belowDService', ['dMin' => $this->mm($dMin), 'dService' => $this->mm($dService)]);
            } elseif ($tread) {
                $treadReasons[] = Messages::make('tire.reasons.atOrBelowDPull', ['dMin' => $this->mm($dMin), 'dPull' => $this->mm($dPull)]);
            }
        }
        if (isset(self::UNSUITABLE_WEAR[$a('wear_pattern')])) {
            $tread = true;
            $treadReasons[] = Messages::make('tire.reasons.treadNotSuitable', ['wearPattern' => Messages::make(self::UNSUITABLE_WEAR[$a('wear_pattern')])]);
        }
        if (isset(self::WEAR_FOLLOW_UPS[$a('wear_pattern')])) {
            $followUps[] = Messages::make(self::WEAR_FOLLOW_UPS[$a('wear_pattern')]);
        }

        // ---- damages / repair ---------------------------------------------------------------------
        $needsDamageRows = $a('leak_foreign_object') === 'YES' || $a('inner_liner_condition') === 'LOCAL_DAMAGE';
        if ($needsDamageRows && $damages === []) {
            $hold[] = Messages::make('tire.reasons.leakLocalInnerLinerDamageReported');
        }
        $repairNeeded = $damages !== [];
        $eligible = null;
        if ($repairNeeded) {
            $eligibility = $a('repair_eligibility');
            $specialist = $a('specialist_result');
            if ($eligibility === null) {
                $hold[] = Messages::make('tire.reasons.repairEligibilityDamagesNotBeenAnswered');
            } elseif ($eligibility === 'NO') {
                $scrap[] = Messages::make('tire.reasons.damageNotRepairableWithinLimitsTire');
            } elseif ($eligibility === 'SPECIALIST_REQUIRED') {
                if ($specialist === 'ACCEPTED') {
                    $eligible = true;
                } elseif ($specialist === 'REJECTED') {
                    $scrap[] = Messages::make('tire.reasons.specialistRejectedRepair');
                } else {
                    $hold[] = Messages::make('tire.reasons.specialistDecisionRequiredNoFinalSpecialist');
                }
            } else {
                $eligible = true;
            }
            $this->checkDamages($damages, $profile, $scrap, $hold, $repairFails);
        }

        // ---- casing for retread (K) and specialist / retreader (F) ------------------------------------
        $casingReasons = [];
        $casing = null;
        if ($profile !== null) {
            $casing = true;
            if ($a('casing_compliance') === 'DOES_NOT_MEET') {
                $casing = false;
                $casingReasons[] = Messages::make('tire.reasons.casingDoesNotMeetAgeRetread');
            }
            if ($age !== null && $age > (int) $profile['a_retread_max_months']) {
                $casing = false;
                $casingReasons[] = Messages::make('tire.reasons.casingAgeAgeMonthsExceedsRetread', ['age' => $age, 'a_retread_max_months' => $profile['a_retread_max_months']]);
            }
            if ($facts['retread_count'] >= (int) $profile['n_retread_max']) {
                $casing = false;
                $casingReasons[] = Messages::make('tire.reasons.retreadCountRetreadCountReachedN', ['retread_count' => $facts['retread_count'], 'n_retread_max' => $profile['n_retread_max']]);
            }
        }
        $specialistFinal = $a('specialist_result') === 'ACCEPTED';
        if ($tread === true && $a('specialist_result') === 'REJECTED') {
            $scrap[] = Messages::make('tire.reasons.retreaderSpecialistRejectedCasing');
        }

        // ---- decision ---------------------------------------------------------------------------------
        $repairAllowed = $repairNeeded ? ($eligible === true && $repairFails === []) : null;
        $complete = $hold === [];
        $detail = null;
        $additionalWork = null;
        if ($scrap !== []) {
            [$recommendation, $reasons] = [self::SCRAP, $scrap];
        } elseif (! $complete) {
            [$recommendation, $reasons] = [self::HOLD, $hold];
        } elseif ($tread === true) {
            if ($casing === false) {
                [$recommendation, $reasons] = [self::SCRAP, array_merge($treadReasons, $casingReasons)];
            } elseif ($repairNeeded && ! $repairAllowed) {
                [$recommendation, $reasons] = [self::SCRAP, array_merge($treadReasons, $repairFails)];
            } elseif ($specialistFinal) {
                $recommendation = self::RETREAD;
                $additionalWork = $repairNeeded ? 'CASING_REPAIR' : null;
                $reasons = array_merge($treadReasons, [Messages::make('tire.reasons.casingAcceptedRetreadRetreaderSpecialist')], $repairNeeded ? [Messages::make('tire.reasons.casingDamageWithinRepairLimitsRepair')] : []);
            } else {
                $recommendation = self::HOLD;
                $detail = 'RETREAD_CANDIDATE';
                $reasons = array_merge($treadReasons, [Messages::make('tire.reasons.retreadCandidateCasingAwaitingFinalRetreader')]);
            }
        } elseif ($repairNeeded) {
            [$recommendation, $reasons] = $repairAllowed
                ? [self::REPAIR, [Messages::make('tire.reasons.repairableDamages', ['count' => count($damages)])]]
                : [self::SCRAP, $repairFails];
        } else {
            $recommendation = self::REUSE;
            $reasons = [Messages::make('tire.reasons.inspectionCompleteTreadUsableNoDamage')];
        }

        $reasons = $this->uniqueMessages($reasons);
        $openItems = $this->uniqueMessages($hold);

        return [
            'recommendation' => $recommendation,
            'recommendation_detail' => $detail,
            'additional_work' => $additionalWork,
            // Display text (unchanged English) and the machine-readable form ({code, params}, code = EN-ID
            // dataset key) of the same reasons — logic never depends on the text.
            'reasons' => array_map(Messages::render(...), $reasons),
            'reason_codes' => $reasons,
            'open_items' => array_map(Messages::render(...), $openItems),
            'open_item_codes' => $openItems,
            'follow_ups' => array_map(Messages::render(...), $followUps),
            'follow_up_codes' => $followUps,
            'variables' => [
                'C' => $complete, 'X' => $scrap !== [], 'R' => $repairNeeded, 'P' => $repairAllowed,
                'T' => $tread, 'K' => $casing, 'F' => $specialistFinal,
            ],
            'd_min_mm' => $dMin === null ? null : $this->decimal($dMin),
            'remaining_tread_percent' => $this->remainingTread($dMin, $profile, $dNew),
            'shows_damage_section' => $repairNeeded || $needsDamageRows,
            'shows_specialist_question' => $tread === true || $a('repair_eligibility') === 'SPECIALIST_REQUIRED',
        ];
    }

    /**
     * Remaining Tread (%) = clamp((D_min − D_service) / (D_new − D_service) × 100, 0, 100) — a tread
     * life indicator only: not a safety score, not remaining KM, never a disposition override.
     */
    public function remainingTread(?int $dMin, ?array $profile, ?string $dNew): ?string
    {
        if ($dMin === null || $profile === null || $dNew === null || $dNew === '') {
            return null;
        }
        $service = $this->hundredths((string) $profile['d_service_mm']);
        $new = $this->hundredths($dNew);
        if ($new <= $service) {
            return null;
        }
        // hundredths of a percent, rounded half up, integer arithmetic
        $scaled = intdiv(($dMin - $service) * 100 * 100 * 2 + ($new - $service), 2 * ($new - $service));
        $scaled = max(0, min(10000, $scaled));

        return $this->decimal($scaled);
    }

    /** D_min in hundredths of a mm; adds a HOLD reason unless 3 zones × inner/outer main groove are measured. */
    private function minimumDepth(array $measurements, array &$hold): ?int
    {
        $points = [];
        foreach ($measurements as $m) {
            if (($m['depth_mm'] ?? null) === null || $m['depth_mm'] === '') {
                continue;
            }
            $points[(int) $m['zone'].'|'.$m['groove']] = $this->hundredths((string) $m['depth_mm']);
        }
        $missing = [];
        for ($zone = 1; $zone <= self::MIN_MEASUREMENT_ZONES; $zone++) {
            foreach (['INNER_MAIN', 'OUTER_MAIN'] as $groove) {
                if (! isset($points["{$zone}|{$groove}"])) {
                    $missing[] = Messages::make('tire.reasons.treadPoint', ['zone' => $zone, 'groove' => Messages::make($groove === 'INNER_MAIN' ? 'tire.grooves.innerMain' : 'tire.grooves.outerMain')]);
                }
            }
        }
        if ($missing !== []) {
            $hold[] = Messages::make('tire.reasons.treadPointsMissing', ['points' => $missing]);
        }

        return $points === [] || $missing !== [] ? null : min($points);
    }

    /** Damage completeness and the profile's repair limits (location, size, count, overlap, structure). */
    private function checkDamages(array $damages, ?array $profile, array &$scrap, array &$hold, array &$fails): void
    {
        $limits = $profile['repair_limits'] ?? null;
        foreach (array_values($damages) as $i => $d) {
            $n = $i + 1;
            $label = Messages::make('tire.reasons.damageLabel', [
                'n' => $n,
                'type' => $this->codeLabel(self::DAMAGE_TYPE_KEYS, $d['damage_type'] ?? null),
                'location' => $this->codeLabel(self::DAMAGE_LOCATION_KEYS, $d['location'] ?? null),
            ]);
            $type = $d['damage_type'] ?? null;
            if ($type === 'SEPARATION') {
                $scrap[] = Messages::make('tire.reasons.labelSeparation', ['label' => $label]);
            }
            if (($d['reaches_reinforcement'] ?? null) === 'UNKNOWN') {
                $hold[] = Messages::make('tire.reasons.labelUnknownWhetherReachesReinforcingStructure', ['label' => $label]);
            }
            if (($d['overlaps_previous_repair'] ?? null) === 'UNKNOWN') {
                $hold[] = Messages::make('tire.reasons.labelUnknownWhetherOverlapsPreviousRepair', ['label' => $label]);
            }
            $required = match ($type) {
                'PUNCTURE' => ['diameter_mm'],
                'CUT' => ['length_mm', 'width_mm', 'depth_mm'],
                default => [],
            };
            foreach ($required as $field) {
                if (($d[$field] ?? null) === null || $d[$field] === '') {
                    $hold[] = Messages::make('tire.reasons.limitFieldRequired', ['label' => $label, 'field' => Messages::make(self::DAMAGE_FIELD_KEYS[$field])]);
                }
            }
            if ($limits === null) {
                continue;
            }
            if (! in_array($d['location'] ?? null, $limits['allowed_locations'] ?? [], true)) {
                $fails[] = Messages::make('tire.reasons.labelRepairsNotPermittedLocation', ['label' => $label]);
            }
            if (($d['reaches_reinforcement'] ?? null) === 'YES' && empty($limits['allow_reinforcement_damage'])) {
                $fails[] = Messages::make('tire.reasons.labelReachesReinforcingStructureWhichRepair', ['label' => $label]);
            }
            if (($d['overlaps_previous_repair'] ?? null) === 'YES' && empty($limits['allow_overlap_previous_repair'])) {
                $fails[] = Messages::make('tire.reasons.labelOverlapsPreviousRepairWhichRepair', ['label' => $label]);
            }
            $sizeLimits = match ($type) {
                'PUNCTURE' => ['diameter_mm' => 'max_puncture_diameter_mm'],
                'CUT' => ['length_mm' => 'max_cut_length_mm', 'width_mm' => 'max_cut_width_mm', 'depth_mm' => 'max_cut_depth_mm'],
                default => [],
            };
            foreach ($sizeLimits as $field => $limitKey) {
                $limit = $limits[$limitKey] ?? null;
                if ($limit === null || $limit === '') {
                    $hold[] = Messages::make('tire.reasons.labelRepairLimitLimitKeyNot', ['label' => $label, 'limitKey' => $limitKey]);
                } elseif (($d[$field] ?? null) !== null && $d[$field] !== '' && $this->hundredths((string) $d[$field]) > $this->hundredths((string) $limit)) {
                    $fails[] = Messages::make('tire.reasons.damageSizeExceedsLimit', ['label' => $label, 'field' => Messages::make(self::DAMAGE_FIELD_KEYS[$field]), 'value' => $d[$field], 'limit' => $limit]);
                }
            }
        }
        if ($limits === null) {
            return;
        }
        $max = $limits['max_repairs'] ?? null;
        if ($max === null || $max === '') {
            $hold[] = Messages::make('tire.reasons.repairLimitMaxRepairsNotConfigured');
        } elseif (count($damages) > (int) $max) {
            $fails[] = Messages::make('tire.reasons.damageCountExceedsMax', ['count' => count($damages), 'max' => $max]);
        }
    }

    /**
     * Distinct messages in their original order (two reasons that render the same text are one reason).
     *
     * @param  list<array{code: string, params: array}>  $messages
     * @return list<array{code: string, params: array}>
     */
    private function uniqueMessages(array $messages): array
    {
        $seen = [];
        $unique = [];
        foreach ($messages as $message) {
            $text = Messages::render($message);
            if (! isset($seen[$text])) {
                $seen[$text] = true;
                $unique[] = $message;
            }
        }

        return $unique;
    }

    /**
     * Label message for a known code; an unexpected value keeps its previous rendering (lower-cased, "?"
     * when missing) so historical / foreign input still reads the same.
     */
    private function codeLabel(array $keys, ?string $code): array|string
    {
        if ($code !== null && isset($keys[$code])) {
            return Messages::make($keys[$code]);
        }

        return strtolower(str_replace('_', ' ', $code ?? '?'));
    }

    /** "7.5" → 750 (hundredths of a mm — exact, no floats). */
    private function hundredths(string $value): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $result = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$result : $result;
    }

    private function decimal(int $hundredths): string
    {
        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    private function mm(int $hundredths): string
    {
        return $this->decimal($hundredths).' mm';
    }
}
