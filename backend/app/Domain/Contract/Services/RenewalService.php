<?php

namespace App\Domain\Contract\Services;

use App\Domain\Contract\Models\Contract;

/**
 * Section 16: renewal creates a brand new Contract (preserving all history
 * on the original, untouched, row) linked via renewed_from_contract_id,
 * starting the day after the current contract ends. From there it flows
 * through the normal ContractService draft -> approval pipeline, which
 * provisions a fresh Subscription and raises the renewal invoice exactly
 * like any other contract activation.
 */
class RenewalService
{
    public function __construct(private readonly ContractService $contracts) {}

    public function createRenewalDraft(Contract $existingContract, array $attributes, array $items): Contract
    {
        if (! in_array($existingContract->status, ['ACTIVE', 'EXPIRING', 'EXPIRED'], true)) {
            throw new ContractException('Only an active, expiring, or expired contract can be renewed.');
        }

        $startDate = $existingContract->end_date->copy()->addDay();

        $renewal = $this->contracts->createDraft(
            $existingContract->tenant_id,
            array_merge([
                'start_date' => $startDate,
                'end_date' => $attributes['end_date'],
                'billing_cycle' => $attributes['billing_cycle'] ?? $existingContract->billing_cycle,
                'payment_terms_days' => $attributes['payment_terms_days'] ?? $existingContract->payment_terms_days,
                'grace_period_days' => $attributes['grace_period_days'] ?? $existingContract->grace_period_days,
                'currency' => $existingContract->currency,
                'activation_requires_payment' => $attributes['activation_requires_payment'] ?? $existingContract->activation_requires_payment,
                'renewed_from_contract_id' => $existingContract->id,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
            ], []),
            $items
        );

        return $renewal;
    }
}
