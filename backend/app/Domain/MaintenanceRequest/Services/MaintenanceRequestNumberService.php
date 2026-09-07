<?php

namespace App\Domain\MaintenanceRequest\Services;

use App\Domain\Invoice\Services\NumberSequenceService;

class MaintenanceRequestNumberService
{
    public function __construct(private readonly NumberSequenceService $sequence) {}

    /**
     * Must be called inside the same DB transaction as the request insert
     * so the sequence row lock covers the whole operation (same pattern as
     * InvoiceNumberService/ContractNumberService).
     */
    public function generate(?\DateTimeInterface $date = null): string
    {
        $date ??= now();
        $year = (int) $date->format('Y');
        $number = $this->sequence->next('maintenance_request', $year);

        return sprintf('MR/OPTIFLEET/%d/%06d', $year, $number);
    }
}
