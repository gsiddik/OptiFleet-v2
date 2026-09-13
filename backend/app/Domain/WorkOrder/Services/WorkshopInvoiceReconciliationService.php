<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\WorkshopInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * R1 §7: compares a recorded Workshop Invoice against whatever OptiFleet
 * records exist for the same engagement. Computed live on every call
 * (never persisted/cached) so it can never go stale relative to the
 * underlying Memo/Work Order records. Never rejects or blocks anything —
 * this is a display/decision-support computation only; an authorized user
 * reviews the variance and may record a `reconciliation_note` via
 * WorkshopInvoiceController::updateReconciliationNote().
 *
 * "Expected amount" baseline is the Maintenance Memo's own `cost` field —
 * the estimate captured when the external work was requested, which is
 * the figure most directly comparable to what the partner later invoices
 * for that same engagement. The Work Order's own `estimated_total_cost` is
 * surfaced alongside as additional context, not as the primary comparison
 * target, since it covers the whole Work Order (internal labor/parts plus
 * any external work), not just this one external engagement.
 */
class WorkshopInvoiceReconciliationService
{
    public function reconcile(WorkshopInvoice $invoice): array
    {
        $invoice->loadMissing(['memo', 'workOrder']);
        $memo = $invoice->memo;
        $workOrder = $invoice->workOrder;

        $invoicedAmount = BigDecimal::of((string) $invoice->total_amount);
        $expectedAmount = $memo?->cost !== null ? BigDecimal::of((string) $memo->cost) : null;

        $missingSourceRecords = [];
        if ($expectedAmount === null) {
            $missingSourceRecords[] = 'maintenance_memo_cost_not_recorded';
        }
        if ($workOrder?->estimated_total_cost === null) {
            $missingSourceRecords[] = 'work_order_estimated_total_cost_not_recorded';
        }

        $variance = $expectedAmount !== null ? $invoicedAmount->minus($expectedAmount) : null;
        $variancePercent = ($expectedAmount !== null && ! $expectedAmount->isZero())
            ? $variance->dividedBy($expectedAmount, 4, RoundingMode::HALF_UP)->multipliedBy(100)
            : null;

        $status = match (true) {
            $expectedAmount === null => 'NO_EXPECTED_AMOUNT',
            $variance->isZero() => 'MATCHED',
            default => 'VARIANCE',
        };

        $unmatchedLineItems = [];
        if (! empty($invoice->line_items) && $memo === null) {
            $unmatchedLineItems[] = 'invoice_has_line_items_but_no_source_memo_to_compare_against';
        }

        return [
            'expected_amount' => $expectedAmount !== null ? (string) $expectedAmount : null,
            'invoiced_amount' => (string) $invoicedAmount,
            'variance_amount' => $variance !== null ? (string) $variance : null,
            'variance_percent' => $variancePercent !== null ? (string) $variancePercent : null,
            'reconciliation_status' => $status,
            'missing_source_records' => $missingSourceRecords,
            'unmatched_line_items' => $unmatchedLineItems,
            'work_order_estimated_total_cost' => $workOrder?->estimated_total_cost !== null ? (string) $workOrder->estimated_total_cost : null,
            'reconciliation_note' => $invoice->reconciliation_note,
        ];
    }
}
