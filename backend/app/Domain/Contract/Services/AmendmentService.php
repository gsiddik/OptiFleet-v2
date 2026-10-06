<?php

namespace App\Domain\Contract\Services;

use App\Domain\Billing\Models\Billing;
use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Models\ContractAmendment;
use App\Domain\Contract\Models\ContractAmendmentItem;
use App\Domain\Contract\Models\ContractItem;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Invoice\Models\Invoice;
use App\Domain\Invoice\Models\InvoiceItem;
use App\Domain\Invoice\Services\InvoiceNumberService;
use App\Domain\Pricing\Services\PricingResolutionService;
use App\Domain\Pricing\Support\Money;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use App\Domain\Shared\Support\Messages;
use App\Domain\Subscription\Services\EntitlementProvisioningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contract amendments (Section 14/15): never destructively edit an active
 * contract. An amendment is a draft of ADD/REMOVE line changes, reviewed
 * and approved, then applied — which creates new ContractItems (or closes
 * out removed ones by setting valid_until, never deleting them), updates
 * entitlements, and raises a prorated adjustment invoice for the remainder
 * of the current billing period when applicable.
 */
class AmendmentService
{
    public function __construct(
        private readonly PricingResolutionService $pricing,
        private readonly ModuleDependencyService $dependencies,
        private readonly EntitlementService $entitlements,
        private readonly EntitlementProvisioningService $provisioning,
        private readonly InvoiceNumberService $invoiceNumbers,
    ) {}

    public function createDraft(Contract $contract, string $reason, string $effectiveDate, string $createdByUserId): ContractAmendment
    {
        if (! in_array($contract->status, ['ACTIVE', 'APPROVED'], true)) {
            throw new AmendmentException('Only an active contract can be amended.');
        }

        $nextNumber = ($contract->amendments()->max('amendment_number') ?? 0) + 1;

        return ContractAmendment::query()->create([
            'contract_id' => $contract->id,
            'amendment_number' => $nextNumber,
            'status' => 'DRAFT',
            'reason' => $reason,
            'before_snapshot' => $this->snapshot($contract),
            'effective_date' => $effectiveDate,
            'created_by' => $createdByUserId,
        ]);
    }

    public function addItem(ContractAmendment $amendment, array $item): ContractAmendmentItem
    {
        $this->assertDraft($amendment);

        $contract = $amendment->contract;
        $unitPrice = $item['unit_price'] ?? null;

        if ($unitPrice === null && in_array($item['product_type'], ['MODULE', 'ADD_ON', 'CAPACITY'], true)) {
            $resolved = $this->pricing->resolveForTenant(
                $contract->tenant_id,
                $item['product_type'],
                $item['product_reference'],
                $item['billing_frequency'] ?? $contract->billing_cycle,
                $amendment->effective_date->toDateString(),
            );
            $unitPrice = $resolved['amount'];
        }

        $quantity = (string) ($item['quantity'] ?? 1);
        $finalAmount = Money::multiply($unitPrice, $quantity);

        return ContractAmendmentItem::query()->create([
            'contract_amendment_id' => $amendment->id,
            'action' => 'ADD',
            'product_type' => $item['product_type'],
            'product_reference' => $item['product_reference'] ?? null,
            'description' => $item['description'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'final_amount' => $finalAmount,
        ]);
    }

    public function removeItem(ContractAmendment $amendment, string $contractItemId): ContractAmendmentItem
    {
        $this->assertDraft($amendment);

        $contract = $amendment->contract;
        $contractItem = ContractItem::query()->where('contract_id', $contract->id)->findOrFail($contractItemId);

        if (in_array($contractItem->product_type, ['MODULE', 'ADD_ON'], true) && $contractItem->product_reference) {
            $this->assertNoActiveDependents($contract, $contractItem->product_reference);
        }

        return ContractAmendmentItem::query()->create([
            'contract_amendment_id' => $amendment->id,
            'action' => 'REMOVE',
            'product_type' => $contractItem->product_type,
            'product_reference' => $contractItem->product_reference,
            'description' => $contractItem->description,
            'quantity' => $contractItem->quantity,
            'unit_price' => $contractItem->unit_price,
            'final_amount' => $contractItem->final_amount,
            'contract_item_id' => $contractItem->id,
        ]);
    }

    /**
     * A module can only be removed if no other currently-active contract
     * item still depends on it (Section 15) — reuses the Phase 1 module
     * dependency graph's reverse-dependents lookup.
     */
    private function assertNoActiveDependents(Contract $contract, string $moduleCode): void
    {
        $module = Module::query()->where('code', $moduleCode)->first();
        if (! $module) {
            return;
        }

        $activeCodes = $contract->items()
            ->whereIn('product_type', ['MODULE', 'ADD_ON'])
            ->whereNull('valid_until')
            ->pluck('product_reference');

        $dependents = $this->dependencies->directDependents($module)->pluck('code');
        $blocking = $dependents->intersect($activeCodes);

        if ($blocking->isNotEmpty()) {
            throw new AmendmentException(
                "Cannot remove module {$moduleCode}: still required by active module(s) on this contract: ".$blocking->implode(', ')
            );
        }
    }

    public function submitForApproval(ContractAmendment $amendment): ContractAmendment
    {
        $this->assertDraft($amendment);
        if ($amendment->items()->count() === 0) {
            throw new AmendmentException('Cannot submit an amendment with no changes.');
        }

        $amendment->update(['status' => 'PENDING_APPROVAL']);

        return $amendment;
    }

    public function approve(ContractAmendment $amendment, string $approverUserId): ContractAmendment
    {
        if ($amendment->status !== 'PENDING_APPROVAL') {
            throw new AmendmentException('Only an amendment pending approval can be approved.');
        }

        return DB::transaction(function () use ($amendment, $approverUserId) {
            $amendment->update([
                'status' => 'APPROVED',
                'approved_by' => $approverUserId,
                'approved_at' => now(),
            ]);

            $this->apply($amendment);

            $amendment->update(['status' => 'APPLIED']);

            return $amendment->fresh();
        });
    }

    public function reject(ContractAmendment $amendment, ?string $note = null): ContractAmendment
    {
        if ($amendment->status !== 'PENDING_APPROVAL') {
            throw new AmendmentException('Only an amendment pending approval can be rejected.');
        }

        $amendment->update(['status' => 'REJECTED', 'reason' => trim(($amendment->reason ?? '')."\n".Messages::text('contract.notes.amendmentRejected', ['note' => $note]))]);

        return $amendment->fresh();
    }

    private function apply(ContractAmendment $amendment): void
    {
        $contract = $amendment->contract;
        $effectiveDate = $amendment->effective_date;
        $prorationTotal = '0';

        [$periodStart, $periodEnd] = $this->currentBillingPeriod($contract, $effectiveDate);

        foreach ($amendment->items as $item) {
            if ($item->action === 'REMOVE') {
                $contractItem = $item->contractItem;
                $contractItem->update(['valid_until' => $effectiveDate->copy()->subDay()]);

                if (in_array($contractItem->product_type, ['MODULE', 'ADD_ON'], true) && $contractItem->product_reference) {
                    $module = Module::query()->where('code', $contractItem->product_reference)->first();
                    if ($module) {
                        $this->entitlements->revoke($contract->tenant_id, $module);
                    }
                }

                continue;
            }

            // ADD
            $newItem = ContractItem::query()->create([
                'contract_id' => $contract->id,
                'product_type' => $item->product_type,
                'product_reference' => $item->product_reference,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount' => 0,
                'tax' => 0,
                'final_amount' => $item->final_amount,
                'billing_frequency' => $contract->billing_cycle,
                'valid_from' => $effectiveDate,
            ]);

            $item->update(['contract_item_id' => $newItem->id]);

            if ($periodStart && $periodEnd) {
                $totalDays = $periodStart->diffInDays($periodEnd) + 1;
                $remainingDays = $effectiveDate->diffInDays($periodEnd) + 1;
                if ($remainingDays < $totalDays) {
                    $prorationTotal = Money::add($prorationTotal, Money::prorate((string) $item->final_amount, $remainingDays, $totalDays));
                }
            }
        }

        $this->provisioning->provisionFromContract($contract->fresh('items'));

        if (Money::isGreaterThan($prorationTotal, '0')) {
            $this->raiseAdjustmentInvoice($contract, $amendment, $prorationTotal, $periodEnd);
        }

        $amendment->update([
            'proration_amount' => $prorationTotal,
            'after_snapshot' => $this->snapshot($contract->fresh('items')),
        ]);
    }

    /**
     * Finds the billing period currently in progress at the given date, so
     * a mid-period addition can be prorated for its remainder. Returns
     * [null, null] if no billing has been generated yet for that period
     * (the item will simply be billed in full starting from its
     * valid_from on the next regular billing run).
     */
    private function currentBillingPeriod(Contract $contract, Carbon $date): array
    {
        $subscription = $contract->subscription;
        if (! $subscription) {
            return [null, null];
        }

        $billing = Billing::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_period_start', '<=', $date->toDateString())
            ->where('billing_period_end', '>=', $date->toDateString())
            ->first();

        return $billing ? [$billing->billing_period_start, $billing->billing_period_end] : [null, null];
    }

    private function raiseAdjustmentInvoice(Contract $contract, ContractAmendment $amendment, string $amount, ?Carbon $periodEnd): void
    {
        $subscription = $contract->subscription;
        if (! $subscription) {
            return;
        }

        $invoice = Invoice::query()->create([
            'invoice_number' => $this->invoiceNumbers->generate(),
            'tenant_id' => $contract->tenant_id,
            'contract_id' => $contract->id,
            'subscription_id' => $subscription->id,
            'billing_id' => null,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays($contract->payment_terms_days)->toDateString(),
            'currency' => $contract->currency,
            'subtotal' => $amount,
            'total' => $amount,
            'outstanding_amount' => $amount,
            'status' => 'OUTSTANDING',
            'issued_at' => now(),
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'product_type' => 'OTHER',
            'description' => "Amendment #{$amendment->amendment_number} prorated adjustment through {$periodEnd?->toDateString()}",
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
        ]);
    }

    private function snapshot(Contract $contract): array
    {
        return $contract->items()->whereNull('valid_until')->get()->map(fn (ContractItem $i) => [
            'product_type' => $i->product_type,
            'product_reference' => $i->product_reference,
            'description' => $i->description,
            'quantity' => (string) $i->quantity,
            'unit_price' => (string) $i->unit_price,
            'final_amount' => (string) $i->final_amount,
        ])->all();
    }

    private function assertDraft(ContractAmendment $amendment): void
    {
        if ($amendment->status !== 'DRAFT') {
            throw new AmendmentException('This amendment is no longer in DRAFT status.');
        }
    }
}
