<?php

namespace App\Domain\Intelligence\Risk;

use InvalidArgumentException;

class RiskScorerRegistry
{
    /** @var array<string, RiskScorer> */
    private array $scorers = [];

    public function register(RiskScorer $scorer): void
    {
        $this->scorers[$scorer->target()] = $scorer;
    }

    public function has(string $target): bool
    {
        return isset($this->scorers[$target]);
    }

    public function get(string $target): RiskScorer
    {
        return $this->scorers[$target]
            ?? throw new InvalidArgumentException("No deterministic risk scorer registered for target [{$target}].");
    }

    /** @return string[] */
    public function targets(): array
    {
        return array_keys($this->scorers);
    }
}
