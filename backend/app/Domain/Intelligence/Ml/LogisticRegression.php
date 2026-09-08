<?php

namespace App\Domain\Intelligence\Ml;

/**
 * Phase 7 Section 13: a deliberately simple, fully interpretable
 * baseline — batch gradient descent logistic regression, no external ML
 * library. Every prediction is `sigmoid(intercept + sum(coef_i * z_i))`
 * where z_i is the standardized feature value, so a contribution can
 * always be attributed back to a named feature (Section 20 —
 * explainability). Deterministic given the same inputs (no random
 * initialization: weights start at zero), which matters for prediction
 * reproducibility (Section 10).
 */
class LogisticRegression
{
    /** @var float[] */
    private array $coefficients = [];

    private float $intercept = 0.0;

    /** @var float[] */
    private array $featureMeans = [];

    /** @var float[] */
    private array $featureStds = [];

    /** @var string[] */
    private array $featureNames = [];

    /**
     * @param  array<int, array<string, float>>  $samples  each sample is [feature_name => value]
     * @param  int[]  $labels  0/1
     */
    public function fit(array $featureNames, array $samples, array $labels, int $iterations = 500, float $learningRate = 0.1, float $l2 = 0.01): void
    {
        $this->featureNames = $featureNames;
        $n = count($samples);
        $p = count($featureNames);

        [$this->featureMeans, $this->featureStds] = $this->computeStandardization($featureNames, $samples);

        $X = array_map(fn ($s) => $this->standardize($s), $samples);
        $this->coefficients = array_fill(0, $p, 0.0);
        $this->intercept = 0.0;

        for ($iter = 0; $iter < $iterations; $iter++) {
            $gradW = array_fill(0, $p, 0.0);
            $gradB = 0.0;

            for ($i = 0; $i < $n; $i++) {
                $z = $this->intercept;
                foreach ($X[$i] as $j => $v) {
                    $z += $this->coefficients[$j] * $v;
                }
                $pred = $this->sigmoid($z);
                $error = $pred - $labels[$i];

                foreach ($X[$i] as $j => $v) {
                    $gradW[$j] += $error * $v;
                }
                $gradB += $error;
            }

            for ($j = 0; $j < $p; $j++) {
                $this->coefficients[$j] -= $learningRate * (($gradW[$j] / $n) + $l2 * $this->coefficients[$j]);
            }
            $this->intercept -= $learningRate * ($gradB / $n);
        }
    }

    /** @param array<string, float> $sample */
    public function predictProba(array $sample): float
    {
        $z = $this->intercept;
        foreach ($this->standardize($sample) as $j => $v) {
            $z += $this->coefficients[$j] * $v;
        }

        return $this->sigmoid($z);
    }

    /** Contribution of each feature to this prediction, sorted by absolute magnitude (Section 20). */
    public function contributions(array $sample): array
    {
        $standardized = $this->standardize($sample);
        $contributions = [];
        foreach ($this->featureNames as $j => $name) {
            $contributions[$name] = $this->coefficients[$j] * $standardized[$j];
        }
        uasort($contributions, fn ($a, $b) => abs($b) <=> abs($a));

        return $contributions;
    }

    public function toArtifact(): array
    {
        return [
            'algorithm' => 'logistic_regression',
            'feature_names' => $this->featureNames,
            'coefficients' => $this->coefficients,
            'intercept' => $this->intercept,
            'feature_means' => $this->featureMeans,
            'feature_stds' => $this->featureStds,
        ];
    }

    public static function fromArtifact(array $artifact): self
    {
        $model = new self;
        $model->featureNames = $artifact['feature_names'];
        $model->coefficients = $artifact['coefficients'];
        $model->intercept = $artifact['intercept'];
        $model->featureMeans = $artifact['feature_means'];
        $model->featureStds = $artifact['feature_stds'];

        return $model;
    }

    private function computeStandardization(array $featureNames, array $samples): array
    {
        $means = [];
        $stds = [];
        $n = max(1, count($samples));

        foreach ($featureNames as $name) {
            $values = array_map(fn ($s) => (float) ($s[$name] ?? 0.0), $samples);
            $mean = array_sum($values) / $n;
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;
            $means[$name] = $mean;
            $stds[$name] = sqrt($variance) ?: 1.0;
        }

        return [$means, $stds];
    }

    /** @return float[] indexed like $this->featureNames */
    private function standardize(array $sample): array
    {
        $out = [];
        foreach ($this->featureNames as $name) {
            $value = (float) ($sample[$name] ?? $this->featureMeans[$name] ?? 0.0);
            $mean = $this->featureMeans[$name] ?? 0.0;
            $std = $this->featureStds[$name] ?? 1.0;
            $out[] = ($value - $mean) / $std;
        }

        return $out;
    }

    private function sigmoid(float $z): float
    {
        $z = max(-35.0, min(35.0, $z)); // numeric stability, no behavioral change in the useful range

        return 1 / (1 + exp(-$z));
    }
}
