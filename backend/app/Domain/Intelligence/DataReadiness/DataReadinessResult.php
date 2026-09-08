<?php

namespace App\Domain\Intelligence\DataReadiness;

class DataReadinessResult
{
    public const READY = 'READY';

    public const LIMITED = 'LIMITED';

    public const NOT_READY = 'NOT_READY';

    /** @param string[] $reasons */
    public function __construct(
        public readonly string $status,
        public readonly int $sampleSize,
        public readonly int $positiveCount,
        public readonly float $missingnessRatio,
        public readonly int $observationPeriodDays,
        public readonly float $majorityClassRatio,
        public readonly array $reasons,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'sample_size' => $this->sampleSize,
            'positive_count' => $this->positiveCount,
            'missingness_ratio' => $this->missingnessRatio,
            'observation_period_days' => $this->observationPeriodDays,
            'majority_class_ratio' => $this->majorityClassRatio,
            'reasons' => $this->reasons,
        ];
    }
}
