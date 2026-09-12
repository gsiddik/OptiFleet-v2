<?php

namespace App\Domain\Tire\Services;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Configuration\Services\EffectiveConfigurationResolver;

/** Phase F (BD-2/BD-8): resolves the effective published TIRE_SCORING config for REPAIR or RETREAD — tenant override, else platform default, else null. */
class TireScoringConfigurationService
{
    public function __construct(private readonly EffectiveConfigurationResolver $resolver) {}

    public function resolveEffective(string $scoringType, string $tenantId): ?ConfigurationVersion
    {
        return $this->resolver->resolve(ConfigurationSet::TYPE_TIRE_SCORING, $scoringType, $tenantId);
    }
}
