<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireRepair;
use App\Domain\Tire\Models\TireRetread;
use App\Domain\Tire\Models\TireScoringResult;
use Illuminate\Support\Facades\DB;

/**
 * Phase F (G-31 / BD-1 / BD-3): structured tire scoring — SPA (raw and
 * normalized), KA, KTS/KTN, classification, and KF as supporting
 * information only. This is the calculation FRAMEWORK: it computes
 * exactly what a published TIRE_SCORING configuration tells it to, and
 * refuses to run at all when required inputs are missing/invalid or no
 * configuration is published. It never invents a band, a weight, or a
 * classification label — those come entirely from the versioned
 * configuration a tenant admin (or, once available, a platform
 * specialist) has published. Precision: every stored numeric result is
 * rounded to 2 decimal places using PHP's default round-half-away-from-
 * zero mode (round($value, 2)) — this is a deliberate, tested choice,
 * not an accident of float formatting.
 */
class TireScoringService
{
    private const PRECISION = 2;

    public function __construct(private readonly TireScoringConfigurationService $configuration) {}

    /**
     * @param  string  $scoringType  REPAIR or RETREAD (BD-2: separate, versioned configurations).
     * @param  float|null  $kaScore  Inspector-supplied supplementary condition score — never computed by OptiFleet.
     * @param  bool  $criticalSafetyFail  The inspector's own critical-safety verdict; ORed with the matched band's own is_critical_fail flag.
     */
    public function calculate(
        Tire $tire,
        TireInspection $inspection,
        string $scoringType,
        ?float $kaScore,
        bool $criticalSafetyFail,
        ?string $criticalSafetyReasons,
        ?string $userId,
        ?TireRetread $retread = null,
        ?TireRepair $repair = null,
    ): TireScoringResult {
        if (! in_array($scoringType, ['REPAIR', 'RETREAD'], true)) {
            throw new TireException('scoringType must be REPAIR or RETREAD.');
        }
        if ($inspection->tire_id !== $tire->id) {
            throw new TireException('The inspection does not belong to this tire.');
        }
        if ($criticalSafetyFail && trim((string) $criticalSafetyReasons) === '') {
            throw new TireException('A critical safety failure must be recorded with a reason.');
        }

        // BD-3: reject on missing/zero/invalid/incompatible reference tread depth — never silently approximate.
        $product = $tire->product;
        if (! $product) {
            throw new TireException('This tire has no linked Product — cannot resolve a reference tread depth.');
        }
        if ($product->product_type !== 'TIRE') {
            throw new TireException('This tire\'s linked Product is not a TIRE product — incompatible reference tread depth.');
        }
        $referenceTreadDepth = $product->reference_tread_depth_mm !== null ? (float) $product->reference_tread_depth_mm : null;
        if ($referenceTreadDepth === null || $referenceTreadDepth <= 0) {
            throw new TireException('This tire\'s Product has no valid reference tread depth recorded — scoring cannot be calculated.');
        }

        $measuredTreadDepth = $inspection->tread_depth_mm !== null ? (float) $inspection->tread_depth_mm : null;
        if ($measuredTreadDepth === null || $measuredTreadDepth < 0) {
            throw new TireException('The source inspection has no valid measured tread depth recorded — scoring cannot be calculated.');
        }

        $version = $this->configuration->resolveEffective($scoringType, $tire->tenant_id);
        if (! $version) {
            throw new TireException("No published {$scoringType} tire scoring configuration is available for this tenant — no applicable SPA score.");
        }
        $payload = $version->payload;

        if (($payload['requires_ka'] ?? false) && $kaScore === null) {
            throw new TireException('This configuration requires a KA score to be supplied.');
        }

        $computed = $this->computeFromPayload($payload, $referenceTreadDepth, $measuredTreadDepth, $kaScore, $criticalSafetyFail);

        return DB::transaction(fn () => TireScoringResult::query()->create([
            'tenant_id' => $tire->tenant_id,
            'tire_id' => $tire->id,
            'tire_inspection_id' => $inspection->id,
            'tire_retread_id' => $retread?->id,
            'tire_repair_id' => $repair?->id,
            'scoring_type' => $scoringType,
            'configuration_version_id' => $version->id,
            'reference_tread_depth_mm' => $referenceTreadDepth,
            'measured_tread_depth_mm' => $measuredTreadDepth,
            'spa_raw_percent' => $computed['spa_raw_percent'],
            'spa_normalized_score' => $computed['spa_normalized_score'],
            'classification' => $computed['classification'],
            'ka_score' => $kaScore !== null ? round($kaScore, self::PRECISION) : null,
            'kf_score' => $computed['kf_score'],
            'critical_safety_fail' => $computed['critical_safety_fail'],
            'critical_safety_reasons' => $criticalSafetyReasons,
            'eligible_for_operational_reuse' => $computed['eligible_for_operational_reuse'],
            'computed_by' => $userId,
            'computed_at' => now(),
        ]));
    }

    /**
     * R2 §18: a non-persisting preview of what `calculate()` would produce
     * against an arbitrary (typically still-DRAFT) payload — never creates
     * a TireScoringResult row, never touches Tire/inspection/retread/repair
     * state. Used by ConfigurationController's TIRE_SCORING dry-run branch.
     *
     * @return array{spa_raw_percent:float, spa_normalized_score:float, classification:string, ka_score:?float, kf_score:?float, critical_safety_fail:bool, eligible_for_operational_reuse:bool, missing_inputs:string[]}
     */
    public function dryRun(
        array $payload,
        ?float $referenceTreadDepth,
        ?float $measuredTreadDepth,
        ?float $kaScore,
        bool $criticalSafetyFail,
    ): array {
        $missing = [];
        if ($referenceTreadDepth === null || $referenceTreadDepth <= 0) {
            $missing[] = 'reference_tread_depth_mm';
        }
        if ($measuredTreadDepth === null || $measuredTreadDepth < 0) {
            $missing[] = 'measured_tread_depth_mm';
        }
        if (($payload['requires_ka'] ?? false) && $kaScore === null) {
            $missing[] = 'ka_score';
        }
        if (! empty($missing)) {
            return [
                'spa_raw_percent' => null, 'spa_normalized_score' => null, 'classification' => null,
                'ka_score' => null, 'kf_score' => null, 'critical_safety_fail' => null,
                'eligible_for_operational_reuse' => null, 'missing_inputs' => $missing,
            ];
        }

        $computed = $this->computeFromPayload($payload, $referenceTreadDepth, $measuredTreadDepth, $kaScore, $criticalSafetyFail);
        $computed['ka_score'] = $kaScore !== null ? round($kaScore, self::PRECISION) : null;
        $computed['missing_inputs'] = [];

        return $computed;
    }

    /** @return array{spa_raw_percent:float, spa_normalized_score:float, classification:string, kf_score:?float, critical_safety_fail:bool, eligible_for_operational_reuse:bool} */
    private function computeFromPayload(array $payload, float $referenceTreadDepth, float $measuredTreadDepth, ?float $kaScore, bool $criticalSafetyFail): array
    {
        $spaRaw = round(($measuredTreadDepth / $referenceTreadDepth) * 100, self::PRECISION);
        $band = $this->matchBand($payload['bands'] ?? [], $spaRaw);
        if (! $band) {
            throw new TireException('No configured SPA band matches the calculated percentage — no applicable SPA score.');
        }

        $bandCriticalFail = (bool) ($band['is_critical_fail'] ?? false);
        $finalCriticalFail = $criticalSafetyFail || $bandCriticalFail;
        // The critical-fail gate is absolute (BD-1/BD-6): it forces ineligibility regardless of the band's own flag.
        $eligibleForOperationalReuse = $finalCriticalFail ? false : (bool) $band['eligible_for_operational_reuse'];
        $kfScore = $this->computeKfScore($payload['kf_weights'] ?? null, (float) $band['normalized_score'], $kaScore);

        return [
            'spa_raw_percent' => $spaRaw,
            'spa_normalized_score' => round((float) $band['normalized_score'], self::PRECISION),
            'classification' => $band['classification'],
            'kf_score' => $kfScore,
            'critical_safety_fail' => $finalCriticalFail,
            'eligible_for_operational_reuse' => $eligibleForOperationalReuse,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $bands */
    private function matchBand(array $bands, float $spaRaw): ?array
    {
        usort($bands, fn ($a, $b) => ($a['min_percent'] ?? 0) <=> ($b['min_percent'] ?? 0));
        $last = count($bands) - 1;
        foreach ($bands as $i => $band) {
            $isLast = $i === $last;
            if ($spaRaw >= (float) $band['min_percent'] && ($isLast || $spaRaw < (float) $band['max_percent'])) {
                return $band;
            }
        }

        return null;
    }

    private function computeKfScore(?array $weights, float $spaNormalized, ?float $kaScore): ?float
    {
        if (! $weights) {
            return null;
        }
        $components = ['spa_normalized' => $spaNormalized, 'ka' => $kaScore];
        $sum = 0.0;
        foreach ($weights as $component => $weight) {
            if (! array_key_exists($component, $components) || $components[$component] === null) {
                continue;
            }
            $sum += (float) $weight * $components[$component];
        }

        return round($sum, self::PRECISION);
    }

    /** BD-4/BD-8: a finalized result is immutable; finalizing is itself a maker-checker action distinct from whoever computed it. */
    public function finalize(TireScoringResult $result, ?string $userId): TireScoringResult
    {
        return DB::transaction(function () use ($result, $userId) {
            $locked = TireScoringResult::query()->lockForUpdate()->findOrFail($result->id);
            if ($locked->finalized_at !== null) {
                throw new TireException('This scoring result is already finalized — finalized results are immutable.');
            }
            if ($userId !== null && $userId === $locked->computed_by) {
                throw new TireException('The actor who computed this score cannot also finalize it.');
            }

            $locked->update(['finalized_by' => $userId, 'finalized_at' => now()]);

            return $locked->fresh();
        });
    }
}
