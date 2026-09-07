<?php

namespace App\Domain\Contract\Services;

use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Models\ContractApproval;
use App\Domain\Contract\Models\ContractItem;
use App\Domain\Pricing\Services\PricingResolutionService;
use App\Domain\Pricing\Support\Money;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\Subscription\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;

class ContractService
{
    public function __construct(
        private readonly ContractNumberService $numbers,
        private readonly PricingResolutionService $pricing,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Creates a DRAFT contract and resolves + freezes pricing for every
     * item at creation time (tenant custom price > active standard price).
     * Nothing here is recalculated later from live catalog pricing.
     */
    public function createDraft(string $tenantId, array $attributes, array $items): Contract
    {
        return DB::transaction(function () use ($tenantId, $attributes, $items) {
            $contract = Contract::query()->create(array_merge($attributes, [
                'contract_number' => $this->numbers->generate(),
                'tenant_id' => $tenantId,
                'status' => 'DRAFT',
            ]));

            foreach ($items as $item) {
                $this->addItem($contract, $item);
            }

            $this->recalculateTotals($contract);

            return $contract->fresh('items');
        });
    }

    public function addItem(Contract $contract, array $item): ContractItem
    {
        if (! $contract->isEditable()) {
            throw new ContractException('Only a DRAFT contract can have items added.');
        }

        $unitPrice = $item['unit_price'] ?? null;
        $pricingVersionId = null;
        $bundleVersionId = $item['bundle_version_id'] ?? null;

        if ($unitPrice === null && in_array($item['product_type'], ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY'], true)) {
            $resolved = $this->pricing->resolveForTenant(
                $contract->tenant_id,
                $item['product_type'],
                $item['product_reference'],
                $item['billing_frequency'],
                $item['valid_from'] ?? $contract->start_date?->toDateString(),
            );
            $unitPrice = $resolved['amount'];
            $pricingVersionId = $resolved['pricing_version_id'];
        }

        if ($item['product_type'] === 'BUNDLE' && ! $bundleVersionId) {
            $bundle = Bundle::query()->where('code', $item['product_reference'])->firstOrFail();
            $bundleVersionId = $bundle->latestVersion()?->id;
        }

        $quantity = (string) ($item['quantity'] ?? 1);
        $discount = (string) ($item['discount'] ?? 0);
        $taxRatePercent = (string) ($item['tax_rate_percent'] ?? 0);

        $lineSubtotal = Money::multiply($unitPrice, $quantity);
        $afterDiscount = Money::subtract($lineSubtotal, $discount);
        $tax = Money::percentageOf($afterDiscount, $taxRatePercent);
        $finalAmount = Money::add($afterDiscount, $tax);

        return ContractItem::query()->create([
            'contract_id' => $contract->id,
            'product_type' => $item['product_type'],
            'product_reference' => $item['product_reference'] ?? null,
            'bundle_version_id' => $bundleVersionId,
            'pricing_version_id' => $pricingVersionId,
            'description' => $item['description'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'tax' => $tax,
            'final_amount' => $finalAmount,
            'billing_frequency' => $item['billing_frequency'],
            'valid_from' => $item['valid_from'] ?? $contract->start_date,
            'valid_until' => $item['valid_until'] ?? null,
        ]);
    }

    public function recalculateTotals(Contract $contract): void
    {
        $items = $contract->items()->get();

        $subtotal = Money::add(...$items->map(fn ($i) => Money::multiply($i->unit_price, $i->quantity))->all() ?: ['0']);
        $discount = Money::add(...$items->pluck('discount')->all() ?: ['0']);
        $tax = Money::add(...$items->pluck('tax')->all() ?: ['0']);
        $total = Money::add(...$items->pluck('final_amount')->all() ?: ['0']);

        $contract->update(compact('subtotal', 'discount', 'tax', 'total'));
    }

    public function submitForApproval(Contract $contract): Contract
    {
        if ($contract->status !== 'DRAFT') {
            throw new ContractException('Only a DRAFT contract can be submitted for approval.');
        }
        if ($contract->items()->count() === 0) {
            throw new ContractException('Cannot submit a contract with no items.');
        }

        $contract->update(['status' => 'PENDING_APPROVAL']);

        return $contract;
    }

    /**
     * Approves the contract and immediately provisions its subscription
     * (Section 13): Approved -> Subscription Created -> Initial Invoice
     * Generated -> (Payment Verified ->) Subscription Activated ->
     * Entitlements Activated. If activation_requires_payment is false, the
     * subscription (and contract) activate immediately instead of waiting
     * for a verified payment.
     */
    public function approve(Contract $contract, string $approverUserId, ?string $note = null): Contract
    {
        return DB::transaction(function () use ($contract, $approverUserId, $note) {
            // Row-locked re-check: guards against two concurrent approve()
            // calls both passing a pre-transaction status check and racing
            // to provision a second subscription for the same contract.
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            if ($contract->status !== 'PENDING_APPROVAL') {
                throw new ContractException('Only a contract pending approval can be approved.');
            }

            ContractApproval::query()->create([
                'contract_id' => $contract->id,
                'approver_user_id' => $approverUserId,
                'status' => 'APPROVED',
                'note' => $note,
                'created_at' => now(),
            ]);

            $contract->update(['status' => 'APPROVED', 'approved_at' => now()]);

            $this->subscriptions->provisionForContract($contract->fresh('items'));

            return $contract->fresh();
        });
    }

    public function reject(Contract $contract, string $approverUserId, ?string $note = null): Contract
    {
        if ($contract->status !== 'PENDING_APPROVAL') {
            throw new ContractException('Only a contract pending approval can be rejected.');
        }

        DB::transaction(function () use ($contract, $approverUserId, $note) {
            ContractApproval::query()->create([
                'contract_id' => $contract->id,
                'approver_user_id' => $approverUserId,
                'status' => 'REJECTED',
                'note' => $note,
                'created_at' => now(),
            ]);

            $contract->update(['status' => 'REJECTED']);
        });

        return $contract->fresh();
    }

    public function terminate(Contract $contract, ?string $reason = null): Contract
    {
        if (! in_array($contract->status, ['ACTIVE', 'APPROVED', 'EXPIRING'], true)) {
            throw new ContractException('Only an active contract can be terminated.');
        }

        DB::transaction(function () use ($contract, $reason) {
            $contract->update(['status' => 'TERMINATED', 'notes' => trim(($contract->notes ?? '')."\nTerminated: ".$reason)]);

            $subscription = $contract->subscription;
            if ($subscription) {
                $this->subscriptions->cancel($subscription);
            }
        });

        return $contract->fresh();
    }
}
