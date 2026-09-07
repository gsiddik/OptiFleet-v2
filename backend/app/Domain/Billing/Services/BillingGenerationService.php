<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Billing;
use App\Domain\Billing\Models\BillingItem;
use App\Domain\Pricing\Support\Money;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates one Billing (and its line items) per subscription per billing
 * period, from the subscription's contract items. Idempotent: a unique
 * constraint on (subscription_id, billing_period_start, billing_period_end)
 * guarantees running this twice for the same period never creates two
 * billings — see BillingException / the unique-violation catch below.
 *
 * Proration (Section 23): when a contract item's validity window starts or
 * ends inside the billing period (e.g. a module added mid-period via
 * amendment), its contribution is prorated as
 *   final_amount * covered_days / total_days_in_period
 * both day counts inclusive, rounded half-up to 2 decimals (see
 * App\Domain\Pricing\Support\Money::prorate). Items that fully cover the
 * period are billed at their full final_amount, unprorated.
 */
class BillingGenerationService
{
    public function periodEndFor(Carbon $periodStart, string $billingCycle): Carbon
    {
        return match ($billingCycle) {
            'MONTHLY' => $periodStart->copy()->addMonthNoOverflow()->subDay(),
            'QUARTERLY' => $periodStart->copy()->addMonthsNoOverflow(3)->subDay(),
            'SEMIANNUAL' => $periodStart->copy()->addMonthsNoOverflow(6)->subDay(),
            'ANNUAL' => $periodStart->copy()->addYearNoOverflow()->subDay(),
            default => throw new BillingException('CUSTOM billing cycle requires an explicit period end.'),
        };
    }

    public function generateForSubscription(Subscription $subscription, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): Billing
    {
        $contract = $subscription->contract;
        $periodStart ??= Carbon::parse($subscription->next_billing_date);
        $periodEnd ??= $this->periodEndFor($periodStart, $contract->billing_cycle);

        $existing = Billing::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_period_start', $periodStart->toDateString())
            ->where('billing_period_end', $periodEnd->toDateString())
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($subscription, $contract, $periodStart, $periodEnd) {
                $totalDays = $periodStart->diffInDays($periodEnd) + 1;

                $billing = Billing::query()->create([
                    'subscription_id' => $subscription->id,
                    'tenant_id' => $subscription->tenant_id,
                    'contract_id' => $contract->id,
                    'billing_period_start' => $periodStart->toDateString(),
                    'billing_period_end' => $periodEnd->toDateString(),
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->addDays($contract->payment_terms_days)->toDateString(),
                    'status' => 'DRAFT',
                    'generated_at' => now(),
                ]);

                $subtotal = '0';
                $discountTotal = '0';
                $tax = '0';

                foreach ($contract->items as $item) {
                    if ($item->product_type === 'SETUP_FEE' && $billing->billing_period_start !== $subscription->start_date->toDateString()) {
                        continue; // one-time fee, only on the first period
                    }

                    $itemStart = max($item->valid_from, $periodStart);
                    $itemEnd = $item->valid_until ? min($item->valid_until, $periodEnd) : $periodEnd;

                    if ($itemStart->gt($periodEnd) || $itemEnd->lt($periodStart)) {
                        continue; // item not active during this period at all
                    }

                    $coveredDays = $itemStart->diffInDays($itemEnd) + 1;
                    $isFullPeriod = $coveredDays >= $totalDays;
                    $base = Money::multiply($item->unit_price, $item->quantity);

                    if ($isFullPeriod) {
                        $lineDiscount = (string) $item->discount;
                        $lineTax = (string) $item->tax;
                    } else {
                        $base = Money::prorate($base, $coveredDays, $totalDays);
                        $lineDiscount = Money::prorate((string) $item->discount, $coveredDays, $totalDays);
                        $lineTax = Money::prorate((string) $item->tax, $coveredDays, $totalDays);
                    }

                    $amount = Money::add(Money::subtract($base, $lineDiscount), $lineTax);

                    BillingItem::query()->create([
                        'billing_id' => $billing->id,
                        'contract_item_id' => $item->id,
                        'product_type' => $item->product_type,
                        'product_reference' => $item->product_reference,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'proration_factor' => $isFullPeriod ? null : round($coveredDays / $totalDays, 4),
                        'discount' => $lineDiscount,
                        'tax' => $lineTax,
                        'amount' => $amount,
                    ]);

                    $subtotal = Money::add($subtotal, $base);
                    $discountTotal = Money::add($discountTotal, $lineDiscount);
                    $tax = Money::add($tax, $lineTax);
                }

                $total = Money::add(Money::subtract($subtotal, $discountTotal), $tax);

                $billing->update([
                    'subtotal' => $subtotal,
                    'discount' => $discountTotal,
                    'tax' => $tax,
                    'total' => $total,
                    'status' => 'GENERATED',
                ]);

                $subscription->update(['next_billing_date' => $periodEnd->copy()->addDay()->toDateString()]);

                return $billing->fresh('items');
            });
        } catch (QueryException $e) {
            // Unique-constraint race: another process generated this exact
            // period concurrently — return that one instead of duplicating.
            if (str_contains($e->getMessage(), 'billings_period_unique')) {
                return Billing::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('billing_period_start', $periodStart->toDateString())
                    ->where('billing_period_end', $periodEnd->toDateString())
                    ->firstOrFail();
            }
            throw $e;
        }
    }
}
