<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Invoice\Services\NumberSequenceService;

/**
 * Section 22: temporary built-in numbering, deliberately isolated behind
 * this one-method service so a future configurable numbering engine
 * (Phase 5) can replace it without touching WorkOrderService callers.
 */
class WorkOrderNumberService
{
    public function __construct(private readonly NumberSequenceService $sequence) {}

    public function generate(?\DateTimeInterface $date = null): string
    {
        $date ??= now();
        $year = (int) $date->format('Y');
        $number = $this->sequence->next('work_order', $year);

        return sprintf('WO/OPTIFLEET/%d/%06d', $year, $number);
    }
}
