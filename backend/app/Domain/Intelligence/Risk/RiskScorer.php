<?php

namespace App\Domain\Intelligence\Risk;

/**
 * Deterministic/statistical fallback scorer (Section 8) for one model
 * target. Always available, regardless of ML model readiness — this is
 * what actually serves a prediction whenever no ACTIVE model exists.
 */
interface RiskScorer
{
    public function target(): string;

    /** @return array{score: float, factors: array<int, array{factor: string, count: float, weight: float, contribution: float}>} */
    public function score(array $features): array;
}
