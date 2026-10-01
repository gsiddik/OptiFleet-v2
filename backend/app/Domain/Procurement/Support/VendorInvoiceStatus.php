<?php

namespace App\Domain\Procurement\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vendor invoice status — the backend is the single source of truth:
 *
 *   PAID      a payment is recorded (persisted lifecycle fact)
 *   LATE      unpaid and today > due date
 *   DUE_SOON  unpaid and the due date is within `procurement.invoice_due_soon_days` days
 *   NEW       unpaid and not yet due soon (also legacy invoices without a due date)
 *
 * Only PAID is stored; the temporal statuses are derived from the due date and "today" (in the
 * tenant's time zone), so they can never go stale.
 */
final class VendorInvoiceStatus
{
    public const STATUSES = ['NEW', 'DUE_SOON', 'LATE', 'PAID'];

    public static function dueSoonDays(): int
    {
        return max(0, (int) config('procurement.invoice_due_soon_days', 7));
    }

    public static function today(?string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone ?: config('app.timezone'))->startOfDay();
    }

    public static function resolve(?CarbonInterface $dueDate, bool $paid, CarbonImmutable $today): string
    {
        if ($paid) {
            return 'PAID';
        }
        if ($dueDate === null) {
            return 'NEW';
        }
        $due = CarbonImmutable::parse($dueDate->toDateString());
        if ($today->greaterThan($due)) {
            return 'LATE';
        }

        return $today->addDays(self::dueSoonDays())->greaterThanOrEqualTo($due) ? 'DUE_SOON' : 'NEW';
    }

    /**
     * Same rule as {@see resolve()}, as a query constraint on vendor_invoice_references.
     * `$isPaid` adds the "has a payment" condition (or its negation) to the query.
     *
     * @param  callable(Builder, bool): void  $isPaid
     */
    public static function constrain(Builder $query, string $status, CarbonImmutable $today, callable $isPaid): void
    {
        if ($status === 'PAID') {
            $isPaid($query, true);

            return;
        }
        $isPaid($query, false);
        $soon = $today->addDays(self::dueSoonDays())->toDateString();
        match ($status) {
            'LATE' => $query->where('due_date', '<', $today->toDateString()),
            'DUE_SOON' => $query->whereBetween('due_date', [$today->toDateString(), $soon]),
            default => $query->where(fn (Builder $q) => $q->whereNull('due_date')->orWhere('due_date', '>', $soon)),
        };
    }
}
