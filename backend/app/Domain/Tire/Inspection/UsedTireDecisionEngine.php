<?php

namespace App\Domain\Tire\Inspection;

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
    public const REUSE = 'REUSE';

    public const REPAIR = 'REPAIR';

    public const RETREAD = 'RETREAD';

    public const SCRAP = 'SCRAP';

    public const HOLD = 'HOLD';

    public const MIN_MEASUREMENT_ZONES = 3;

    /** Wear patterns that make the tread unsuitable to keep regardless of depth. */
    private const UNSUITABLE_WEAR = ['CUPPING_SCALLOPING' => 'cupping / scalloping', 'FLAT_SPOT' => 'flat spot'];

    /** Uneven wear → vehicle-side follow-up (the tire repair does not fix the root cause). */
    private const WEAR_FOLLOW_UPS = [
        'ONE_SIDED' => 'One-sided wear: check wheel alignment (camber / toe).',
        'CENTER' => 'Center wear: check tire pressure (over-inflation).',
        'BOTH_SIDES' => 'Both-sides wear: check tire pressure (under-inflation) and load.',
        'CUPPING_SCALLOPING' => 'Cupping / scalloping: check suspension (shock absorbers) and wheel balance.',
        'FLAT_SPOT' => 'Flat spot: check brakes / wheel lock-up and suspension.',
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
            $hold[] = 'Tire category is unknown — set the Vehicle Group of the tire product.';
        }
        if ($profile === null) {
            $hold[] = 'No active inspection rule profile for this tire category — thresholds (D_service, D_pull, ages, repair limits) are required.';
        }

        // ---- Q1 / Q2 / unknown answers --------------------------------------------------------
        $questions = [
            'identity_status' => 'Tire identity / category / manufacture date not fully verified',
            'internal_inspected' => 'Interior not inspected after removal from the rim',
            'wear_pattern' => 'Wear pattern not inspected',
            'bulge_separation' => 'Bulge / deformation / separation suspected or not inspected',
            'cord_exposure' => 'Cord / wire exposure suspected or not inspected',
            'sidewall_condition' => 'Sidewall not inspected',
            'bead_condition' => 'Bead not inspected',
            'inner_liner_condition' => 'Inner liner not inspected',
            'run_flat_overheat' => 'Run-flat / low-pressure / overheat history unknown',
            'leak_foreign_object' => 'Leak / foreign object not tested',
            'previous_repair' => 'Previous repair questionable or not inspected',
            'age_chemical' => 'Age / chemical damage suspected or not inspected',
            'casing_compliance' => 'Age / retread / casing compliance cannot yet be confirmed',
        ];
        foreach ($questions as $key => $label) {
            $value = $a($key);
            if ($value === null || in_array($value, self::NOT_KNOWN, true)) {
                $hold[] = $value === null ? "{$label} (not answered)." : "{$label}.";
            }
        }

        // ---- confirmed rejections (X) ---------------------------------------------------------
        if ($a('bulge_separation') === 'PRESENT') {
            $scrap[] = 'Confirmed bulge / deformation / separation.';
        }
        if ($a('cord_exposure') === 'PRESENT') {
            $scrap[] = 'Cord / wire exposed.';
        }
        if ($a('sidewall_condition') === 'DEEP_CUT_CRACK') {
            $scrap[] = 'Deep sidewall cut / crack.';
        }
        if (in_array($a('bead_condition'), ['TORN', 'DEFORMED', 'BEAD_WIRE_DAMAGED'], true)) {
            $scrap[] = 'Bead damage: '.strtolower(str_replace('_', ' ', $a('bead_condition'))).'.';
        }
        if (in_array($a('inner_liner_condition'), ['CRACKED_DELAMINATED', 'WRINKLED_HEAT_DAMAGE', 'CORD_EXPOSED'], true)) {
            $scrap[] = 'Inner liner / casing failure: '.strtolower(str_replace('_', ' ', $a('inner_liner_condition'))).'.';
        }
        if ($a('run_flat_overheat') === 'PHYSICAL_SIGN') {
            $scrap[] = 'Physical sign of run-flat / low-pressure / overheat damage.';
        }
        if ($a('previous_repair') === 'DOES_NOT_MEET') {
            $scrap[] = 'Previous repair does not meet the standard.';
        }
        if ($a('age_chemical') === 'DEGRADED') {
            $scrap[] = 'Permanent chemical / age degradation (hardened, brittle, softened or swollen).';
        }

        // ---- age --------------------------------------------------------------------------------
        $age = $facts['age_months'];
        if ($age === null) {
            $hold[] = 'Tire age unknown — the manufacture date code cannot be read.';
        } elseif ($profile !== null && $age > (int) $profile['a_max_months']) {
            $scrap[] = "Tire age {$age} months exceeds the maximum service age A_max ({$profile['a_max_months']} months).";
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
                $treadReasons[] = 'D_min '.$this->mm($dMin).' is below the minimum service depth D_service '.$this->mm($dService).'.';
            } elseif ($tread) {
                $treadReasons[] = 'D_min '.$this->mm($dMin).' is at or below the planned removal depth D_pull '.$this->mm($dPull).'.';
            }
        }
        if (isset(self::UNSUITABLE_WEAR[$a('wear_pattern')])) {
            $tread = true;
            $treadReasons[] = 'Tread not suitable to retain: '.self::UNSUITABLE_WEAR[$a('wear_pattern')].'.';
        }
        if (isset(self::WEAR_FOLLOW_UPS[$a('wear_pattern')])) {
            $followUps[] = self::WEAR_FOLLOW_UPS[$a('wear_pattern')];
        }

        // ---- damages / repair ---------------------------------------------------------------------
        $needsDamageRows = $a('leak_foreign_object') === 'YES' || $a('inner_liner_condition') === 'LOCAL_DAMAGE';
        if ($needsDamageRows && $damages === []) {
            $hold[] = 'A leak / local inner liner damage was reported — record the damage details.';
        }
        $repairNeeded = $damages !== [];
        $eligible = null;
        if ($repairNeeded) {
            $eligibility = $a('repair_eligibility');
            $specialist = $a('specialist_result');
            if ($eligibility === null) {
                $hold[] = 'Repair eligibility of the damages has not been answered.';
            } elseif ($eligibility === 'NO') {
                $scrap[] = 'Damage is not repairable within the limits for this tire category / model.';
            } elseif ($eligibility === 'SPECIALIST_REQUIRED') {
                if ($specialist === 'ACCEPTED') {
                    $eligible = true;
                } elseif ($specialist === 'REJECTED') {
                    $scrap[] = 'The specialist rejected the repair.';
                } else {
                    $hold[] = 'Specialist decision required — no final specialist result yet.';
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
                $casingReasons[] = 'Casing does not meet the age / retread requirement.';
            }
            if ($age !== null && $age > (int) $profile['a_retread_max_months']) {
                $casing = false;
                $casingReasons[] = "Casing age {$age} months exceeds A_retread_max ({$profile['a_retread_max_months']} months).";
            }
            if ($facts['retread_count'] >= (int) $profile['n_retread_max']) {
                $casing = false;
                $casingReasons[] = "Retread count {$facts['retread_count']} has reached N_retread_max ({$profile['n_retread_max']}).";
            }
        }
        $specialistFinal = $a('specialist_result') === 'ACCEPTED';
        if ($tread === true && $a('specialist_result') === 'REJECTED') {
            $scrap[] = 'The retreader / specialist rejected the casing.';
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
                $reasons = array_merge($treadReasons, ['Casing accepted for retread by the retreader / specialist.'], $repairNeeded ? ['Casing damage within the repair limits: repair it before retreading.'] : []);
            } else {
                $recommendation = self::HOLD;
                $detail = 'RETREAD_CANDIDATE';
                $reasons = array_merge($treadReasons, ['Retread candidate: the casing is awaiting the final retreader inspection.']);
            }
        } elseif ($repairNeeded) {
            [$recommendation, $reasons] = $repairAllowed
                ? [self::REPAIR, [count($damages).' repairable damage(s) within the repair limits; tread still usable.']]
                : [self::SCRAP, $repairFails];
        } else {
            $recommendation = self::REUSE;
            $reasons = ['Inspection complete: tread usable, no damage requiring repair, no rejection condition.'];
        }

        return [
            'recommendation' => $recommendation,
            'recommendation_detail' => $detail,
            'additional_work' => $additionalWork,
            'reasons' => array_values(array_unique($reasons)),
            'open_items' => array_values(array_unique($hold)),
            'follow_ups' => $followUps,
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
                    $missing[] = "zone {$zone} ".strtolower(str_replace('_', ' ', $groove));
                }
            }
        }
        if ($missing !== []) {
            $hold[] = 'Tread depth: at least 6 points are required (3 zones × inner / outer main groove); missing '.implode(', ', $missing).'.';
        }

        return $points === [] || $missing !== [] ? null : min($points);
    }

    /** Damage completeness and the profile's repair limits (location, size, count, overlap, structure). */
    private function checkDamages(array $damages, ?array $profile, array &$scrap, array &$hold, array &$fails): void
    {
        $limits = $profile['repair_limits'] ?? null;
        foreach (array_values($damages) as $i => $d) {
            $n = $i + 1;
            $label = "Damage {$n} (".strtolower(str_replace('_', ' ', (string) ($d['damage_type'] ?? '?'))).' on '.strtolower(str_replace('_', ' ', (string) ($d['location'] ?? '?'))).')';
            $type = $d['damage_type'] ?? null;
            if ($type === 'SEPARATION') {
                $scrap[] = "{$label}: separation.";
            }
            if (($d['reaches_reinforcement'] ?? null) === 'UNKNOWN') {
                $hold[] = "{$label}: unknown whether it reaches the reinforcing structure.";
            }
            if (($d['overlaps_previous_repair'] ?? null) === 'UNKNOWN') {
                $hold[] = "{$label}: unknown whether it overlaps a previous repair.";
            }
            $required = match ($type) {
                'PUNCTURE' => ['diameter_mm'],
                'CUT' => ['length_mm', 'width_mm', 'depth_mm'],
                default => [],
            };
            foreach ($required as $field) {
                if (($d[$field] ?? null) === null || $d[$field] === '') {
                    $hold[] = "{$label}: ".str_replace('_mm', '', $field).' is required.';
                }
            }
            if ($limits === null) {
                continue;
            }
            if (! in_array($d['location'] ?? null, $limits['allowed_locations'] ?? [], true)) {
                $fails[] = "{$label}: repairs are not permitted in this location.";
            }
            if (($d['reaches_reinforcement'] ?? null) === 'YES' && empty($limits['allow_reinforcement_damage'])) {
                $fails[] = "{$label}: reaches the reinforcing structure, which the repair limits do not permit.";
            }
            if (($d['overlaps_previous_repair'] ?? null) === 'YES' && empty($limits['allow_overlap_previous_repair'])) {
                $fails[] = "{$label}: overlaps a previous repair, which the repair limits do not permit.";
            }
            $sizeLimits = match ($type) {
                'PUNCTURE' => ['diameter_mm' => 'max_puncture_diameter_mm'],
                'CUT' => ['length_mm' => 'max_cut_length_mm', 'width_mm' => 'max_cut_width_mm', 'depth_mm' => 'max_cut_depth_mm'],
                default => [],
            };
            foreach ($sizeLimits as $field => $limitKey) {
                $limit = $limits[$limitKey] ?? null;
                if ($limit === null || $limit === '') {
                    $hold[] = "{$label}: the repair limit {$limitKey} is not configured in the rule profile.";
                } elseif (($d[$field] ?? null) !== null && $d[$field] !== '' && $this->hundredths((string) $d[$field]) > $this->hundredths((string) $limit)) {
                    $fails[] = "{$label}: ".str_replace('_mm', '', $field).' '.$d[$field]." mm exceeds the limit of {$limit} mm.";
                }
            }
        }
        if ($limits === null) {
            return;
        }
        $max = $limits['max_repairs'] ?? null;
        if ($max === null || $max === '') {
            $hold[] = 'The repair limit max_repairs is not configured in the rule profile.';
        } elseif (count($damages) > (int) $max) {
            $fails[] = count($damages)." damages exceed the maximum of {$max} repairs.";
        }
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
