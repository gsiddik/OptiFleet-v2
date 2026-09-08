<?php

namespace App\Domain\Intelligence\Labels;

use InvalidArgumentException;

class LabelBuilderRegistry
{
    /** @var array<string, LabelBuilder> */
    private array $builders = [];

    public function register(LabelBuilder $builder): void
    {
        $this->builders[$builder->target()] = $builder;
    }

    public function has(string $target): bool
    {
        return isset($this->builders[$target]);
    }

    public function get(string $target): LabelBuilder
    {
        return $this->builders[$target]
            ?? throw new InvalidArgumentException("No label builder registered for target [{$target}].");
    }
}
