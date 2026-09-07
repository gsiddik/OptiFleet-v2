<?php

namespace App\Domain\Contract\Services;

use App\Domain\Invoice\Services\NumberSequenceService;

class ContractNumberService
{
    public function __construct(private readonly NumberSequenceService $sequence) {}

    public function generate(?\DateTimeInterface $date = null): string
    {
        $date ??= now();
        $year = (int) $date->format('Y');
        $number = $this->sequence->next('contract', $year);

        return sprintf('CTR/OPTIFLEET/%d/%06d', $year, $number);
    }
}
