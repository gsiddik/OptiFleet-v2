<?php

namespace App\Domain\Intelligence\DataReadiness;

class TrainingRow
{
    public function __construct(
        public readonly string $entityId,
        public readonly string $featureDate,
        public readonly array $features,
        public readonly bool $label,
    ) {}
}
