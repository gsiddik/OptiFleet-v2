<?php

namespace App\Domain\Invoice\Services;

class InvoiceNumberService
{
    public function __construct(private readonly NumberSequenceService $sequence) {}

    /**
     * Must be called inside the same DB transaction as the invoice insert
     * so the sequence row lock covers the whole operation.
     */
    public function generate(?\DateTimeInterface $date = null): string
    {
        $date ??= now();
        $year = (int) $date->format('Y');
        $number = $this->sequence->next('invoice', $year);

        return sprintf('INV/OPTIFLEET/%d/%06d', $year, $number);
    }
}
