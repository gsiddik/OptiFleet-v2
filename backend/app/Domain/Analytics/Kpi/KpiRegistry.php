<?php

namespace App\Domain\Analytics\Kpi;

use InvalidArgumentException;

class KpiRegistry
{
    /** @var array<string, KpiDefinition> */
    private array $definitions = [];

    public function register(KpiDefinition $definition): void
    {
        $this->definitions[$definition->code] = $definition;
    }

    public function get(string $code): KpiDefinition
    {
        return $this->definitions[$code] ?? throw new InvalidArgumentException("Unknown KPI code [{$code}].");
    }

    public function has(string $code): bool
    {
        return isset($this->definitions[$code]);
    }

    /** @return KpiDefinition[] */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
