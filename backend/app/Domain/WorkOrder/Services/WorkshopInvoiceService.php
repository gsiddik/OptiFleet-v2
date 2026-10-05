<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Integration\Services\IntegrationOutboxService;
use App\Domain\WorkOrder\Models\WorkOrderExternalService;
use App\Domain\WorkOrder\Models\WorkshopInvoice;
use App\Domain\WorkOrder\Models\WorkshopInvoiceCancellation;
use App\Domain\WorkOrder\Models\WorkshopInvoiceCorrection;
use App\Domain\WorkOrder\Models\WorkshopInvoicePayment;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * R1 (Workshop Invoice and Settlement) — authoritative business decision:
 * a Workshop Invoice is issued EXTERNALLY by the Workshop Partner; an
 * OptiFleet user only RECORDS it. This service therefore never issues,
 * numbers, or approves an invoice on OptiFleet's behalf — every method
 * here transcribes, validates, reconciles, or tracks a document that
 * already exists outside this system.
 *
 * Lifecycle: a Maintenance Memo (WorkOrderExternalService) must be
 * COMPLETED before an invoice can be recorded against it (`record()`),
 * which moves the memo COMPLETED -> BILLED. Uploading valid payment
 * evidence (`recordPayment()`) moves it BILLED -> PAID. Both transitions
 * are transactional with their triggering write, matching this project's
 * established invariant that a status transition and its supporting
 * record either both succeed or both roll back.
 *
 * The invoice's OWN `status` (RECORDED / CORRECTION_REQUESTED /
 * CANCELLATION_REQUESTED / CANCELLED) is a maker-checker workflow
 * entirely separate from the memo's status — mirroring the established
 * `UsedPartDispositionService` pattern exactly: self-approval is rejected
 * here (the underlying `WorkflowApprovalService`/engine is not used
 * because this workflow has no branching/conditions to justify it — a
 * plain two-user check is simpler and equally auditable).
 */
class WorkshopInvoiceService
{
    public function __construct(private readonly IntegrationOutboxService $outbox) {}

    public static function normalizeInvoiceNumber(string $raw): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($raw)));
    }

    /**
     * Records an externally-issued Workshop Invoice against a COMPLETED
     * Maintenance Memo. Moves the memo to BILLED. One invoice per memo —
     * a memo already linked to an (active) invoice cannot be billed again.
     */
    public function record(WorkOrderExternalService $memo, array $attributes, string $userId): WorkshopInvoice
    {
        return DB::transaction(function () use ($memo, $attributes, $userId) {
            $lockedMemo = WorkOrderExternalService::query()->lockForUpdate()->findOrFail($memo->id);

            if ($lockedMemo->status !== 'COMPLETED') {
                throw new WorkOrderException("Cannot record a Service Invoice against a memo that is {$lockedMemo->status} (must be COMPLETED).");
            }
            if ($lockedMemo->workshop_invoice_id !== null) {
                throw new WorkOrderException('This Maintenance Memo already has a Service Invoice recorded against it.');
            }

            $normalized = self::normalizeInvoiceNumber($attributes['external_invoice_number']);
            $duplicate = WorkshopInvoice::query()
                ->where('tenant_id', $lockedMemo->tenant_id)
                ->where('partner_id', $lockedMemo->partner_id)
                ->where('external_invoice_number_normalized', $normalized)
                ->where('status', '!=', 'CANCELLED')
                ->exists();
            if ($duplicate) {
                throw new WorkOrderException("A Service Invoice numbered '{$attributes['external_invoice_number']}' is already recorded for this service provider.");
            }

            $totalAmount = BigDecimal::of((string) $attributes['total_amount'])->toScale(4);
            if ($totalAmount->isNegativeOrZero()) {
                throw new WorkOrderException('Invoice total_amount must be greater than zero.');
            }

            $invoice = WorkshopInvoice::query()->create([
                'tenant_id' => $lockedMemo->tenant_id,
                'work_order_external_service_id' => $lockedMemo->id,
                'work_order_id' => $lockedMemo->work_order_id,
                'partner_id' => $lockedMemo->partner_id,
                'external_invoice_number' => $attributes['external_invoice_number'],
                'external_invoice_number_normalized' => $normalized,
                'invoice_date' => $attributes['invoice_date'],
                'due_date' => $attributes['due_date'] ?? null,
                'currency' => $attributes['currency'] ?? 'IDR',
                'subtotal' => isset($attributes['subtotal']) ? (string) BigDecimal::of((string) $attributes['subtotal'])->toScale(4) : null,
                'tax_total' => isset($attributes['tax_total']) ? (string) BigDecimal::of((string) $attributes['tax_total'])->toScale(4) : null,
                'discount_total' => isset($attributes['discount_total']) ? (string) BigDecimal::of((string) $attributes['discount_total'])->toScale(4) : null,
                'total_amount' => (string) $totalAmount,
                'line_items' => $attributes['line_items'] ?? null,
                'partner_reference' => $attributes['partner_reference'] ?? null,
                'returned_memo_attachment_url' => $attributes['returned_memo_attachment_url'] ?? null,
                'invoice_attachment_url' => $attributes['invoice_attachment_url'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => 'RECORDED',
                'received_by' => $userId,
                'received_at' => now(),
            ]);

            $lockedMemo->update(['status' => 'BILLED', 'workshop_invoice_id' => $invoice->id]);

            $this->outbox->record(
                $lockedMemo->tenant_id, 'workshop_invoice.recorded', WorkshopInvoice::class, $invoice->id,
                ['work_order_id' => $lockedMemo->work_order_id, 'work_order_external_service_id' => $lockedMemo->id, 'partner_id' => $lockedMemo->partner_id],
                ['external_invoice_number' => $invoice->external_invoice_number, 'total_amount' => (string) $invoice->total_amount, 'currency' => $invoice->currency],
            );
            $this->outbox->record(
                $lockedMemo->tenant_id, 'maintenance_memo.billed', WorkOrderExternalService::class, $lockedMemo->id,
                ['work_order_id' => $lockedMemo->work_order_id, 'workshop_invoice_id' => $invoice->id, 'partner_id' => $lockedMemo->partner_id],
                ['memo_number' => $lockedMemo->memo_number, 'workshop_invoice_id' => $invoice->id],
            );

            return $invoice->fresh();
        });
    }

    public function requestCorrection(WorkshopInvoice $invoice, array $requestedValues, string $reason, string $userId): WorkshopInvoiceCorrection
    {
        return DB::transaction(function () use ($invoice, $requestedValues, $reason, $userId) {
            $locked = WorkshopInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== 'RECORDED') {
                throw new WorkOrderException("Cannot request a correction for an invoice that is {$locked->status} (must be RECORDED).");
            }

            $previousValues = collect($requestedValues)->keys()->mapWithKeys(fn ($key) => [$key => $locked->getAttribute($key)])->all();
            if (isset($requestedValues['external_invoice_number'])) {
                $requestedValues['external_invoice_number_normalized'] = self::normalizeInvoiceNumber($requestedValues['external_invoice_number']);
                $previousValues['external_invoice_number_normalized'] = $locked->external_invoice_number_normalized;
            }

            $correction = WorkshopInvoiceCorrection::query()->create([
                'tenant_id' => $locked->tenant_id,
                'workshop_invoice_id' => $locked->id,
                'previous_values' => $previousValues,
                'requested_values' => $requestedValues,
                'reason' => $reason,
                'status' => 'PENDING',
                'requested_by' => $userId,
                'requested_at' => now(),
            ]);

            $locked->update(['status' => 'CORRECTION_REQUESTED']);

            return $correction;
        });
    }

    public function decideCorrection(WorkshopInvoiceCorrection $correction, string $decision, string $userId, ?string $note = null): WorkshopInvoiceCorrection
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new WorkOrderException("Invalid decision '{$decision}' — must be APPROVE or REJECT.");
        }

        return DB::transaction(function () use ($correction, $decision, $userId, $note) {
            $lockedCorrection = WorkshopInvoiceCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            if ($lockedCorrection->status !== 'PENDING') {
                throw new WorkOrderException("Cannot decide a correction that is {$lockedCorrection->status} (must be PENDING).");
            }
            if ($userId === $lockedCorrection->requested_by) {
                throw new WorkOrderException('The maker who requested this correction cannot also verify it.');
            }

            $lockedInvoice = WorkshopInvoice::query()->lockForUpdate()->findOrFail($lockedCorrection->workshop_invoice_id);
            if ($lockedInvoice->status !== 'CORRECTION_REQUESTED') {
                throw new WorkOrderException("Cannot decide a correction against an invoice that is {$lockedInvoice->status} (must be CORRECTION_REQUESTED).");
            }

            $lockedCorrection->update([
                'status' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            if ($decision === 'APPROVE') {
                $lockedInvoice->update(array_merge($lockedCorrection->requested_values, ['status' => 'RECORDED']));
                $this->outbox->record(
                    $lockedInvoice->tenant_id, 'workshop_invoice.corrected', WorkshopInvoice::class, $lockedCorrection->id,
                    ['workshop_invoice_id' => $lockedInvoice->id, 'work_order_id' => $lockedInvoice->work_order_id],
                    ['requested_values' => $lockedCorrection->requested_values, 'previous_values' => $lockedCorrection->previous_values],
                );
            } else {
                $lockedInvoice->update(['status' => 'RECORDED']);
            }

            return $lockedCorrection->fresh();
        });
    }

    public function requestCancellation(WorkshopInvoice $invoice, string $reason, string $userId): WorkshopInvoiceCancellation
    {
        return DB::transaction(function () use ($invoice, $reason, $userId) {
            $locked = WorkshopInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== 'RECORDED') {
                throw new WorkOrderException("Cannot request cancellation for an invoice that is {$locked->status} (must be RECORDED).");
            }

            $cancellation = WorkshopInvoiceCancellation::query()->create([
                'tenant_id' => $locked->tenant_id,
                'workshop_invoice_id' => $locked->id,
                'reason' => $reason,
                'status' => 'PENDING',
                'requested_by' => $userId,
                'requested_at' => now(),
            ]);

            $locked->update(['status' => 'CANCELLATION_REQUESTED']);

            return $cancellation;
        });
    }

    /**
     * Approving a cancellation never deletes or silently reverses a payment —
     * a payment row that already exists is preserved exactly as-is; only the
     * memo's status is restored (PAID/BILLED -> COMPLETED) and the invoice
     * itself moves to the terminal CANCELLED state, blocking any further
     * payment recording against it.
     */
    public function decideCancellation(WorkshopInvoiceCancellation $cancellation, string $decision, string $userId, ?string $note = null): WorkshopInvoiceCancellation
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new WorkOrderException("Invalid decision '{$decision}' — must be APPROVE or REJECT.");
        }

        return DB::transaction(function () use ($cancellation, $decision, $userId, $note) {
            $lockedCancellation = WorkshopInvoiceCancellation::query()->lockForUpdate()->findOrFail($cancellation->id);
            if ($lockedCancellation->status !== 'PENDING') {
                throw new WorkOrderException("Cannot decide a cancellation that is {$lockedCancellation->status} (must be PENDING).");
            }
            if ($userId === $lockedCancellation->requested_by) {
                throw new WorkOrderException('The maker who requested this cancellation cannot also verify it.');
            }

            $lockedInvoice = WorkshopInvoice::query()->lockForUpdate()->findOrFail($lockedCancellation->workshop_invoice_id);
            if ($lockedInvoice->status !== 'CANCELLATION_REQUESTED') {
                throw new WorkOrderException("Cannot decide a cancellation against an invoice that is {$lockedInvoice->status} (must be CANCELLATION_REQUESTED).");
            }

            $lockedCancellation->update([
                'status' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            if ($decision === 'APPROVE') {
                $lockedInvoice->update(['status' => 'CANCELLED']);

                $lockedMemo = WorkOrderExternalService::query()->lockForUpdate()->findOrFail($lockedInvoice->work_order_external_service_id);
                if (in_array($lockedMemo->status, ['BILLED', 'PAID'], true)) {
                    // Payment row (if any) is left completely untouched — this only
                    // restores the memo's own status; see method docblock.
                    $lockedMemo->update(['status' => 'COMPLETED', 'workshop_invoice_id' => null]);
                }

                $this->outbox->record(
                    $lockedInvoice->tenant_id, 'workshop_invoice.cancelled', WorkshopInvoice::class, $lockedCancellation->id,
                    ['workshop_invoice_id' => $lockedInvoice->id, 'work_order_id' => $lockedInvoice->work_order_id],
                    ['reason' => $lockedCancellation->reason, 'had_payment' => $lockedInvoice->payment()->exists()],
                );
            } else {
                $lockedInvoice->update(['status' => 'RECORDED']);
            }

            return $lockedCancellation->fresh();
        });
    }

    /**
     * Records mandatory payment evidence against a BILLED memo's invoice,
     * moving it to PAID. Exactly one payment per invoice (no partial-payment
     * workflow — see WorkshopInvoicePayment's own docblock); `paid_amount`
     * must exactly equal the invoice's `total_amount`.
     */
    public function recordPayment(WorkshopInvoice $invoice, array $attributes, string $userId): WorkshopInvoicePayment
    {
        return DB::transaction(function () use ($invoice, $attributes, $userId) {
            $lockedInvoice = WorkshopInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($lockedInvoice->status !== 'RECORDED') {
                throw new WorkOrderException("Cannot record payment for an invoice that is {$lockedInvoice->status} (must be RECORDED, with no pending correction/cancellation).");
            }

            $lockedMemo = WorkOrderExternalService::query()->lockForUpdate()->findOrFail($lockedInvoice->work_order_external_service_id);
            if ($lockedMemo->status !== 'BILLED') {
                throw new WorkOrderException("Cannot record payment — the related Maintenance Memo is {$lockedMemo->status} (must be BILLED).");
            }

            if (WorkshopInvoicePayment::query()->where('workshop_invoice_id', $lockedInvoice->id)->exists()) {
                throw new WorkOrderException('Payment has already been recorded for this Service Invoice.');
            }

            $paidAmount = BigDecimal::of((string) $attributes['paid_amount'])->toScale(4);
            $payableAmount = BigDecimal::of((string) $lockedInvoice->total_amount)->toScale(4);
            if (! $paidAmount->isEqualTo($payableAmount)) {
                throw new WorkOrderException("Paid amount ({$paidAmount}) does not match the invoice's total amount ({$payableAmount}). Partial payment is not supported — request a correction first if the invoice total was wrong.");
            }

            $payment = WorkshopInvoicePayment::query()->create([
                'tenant_id' => $lockedInvoice->tenant_id,
                'workshop_invoice_id' => $lockedInvoice->id,
                'payment_date' => $attributes['payment_date'],
                'paid_amount' => (string) $paidAmount,
                'payment_method' => $attributes['payment_method'] ?? null,
                'reference_number' => $attributes['reference_number'] ?? null,
                'evidence_url' => $attributes['evidence_url'],
                'notes' => $attributes['notes'] ?? null,
                'uploaded_by' => $userId,
                'uploaded_at' => now(),
            ]);

            $lockedMemo->update(['status' => 'PAID']);

            $this->outbox->record(
                $lockedInvoice->tenant_id, 'workshop_invoice.payment_recorded', WorkshopInvoicePayment::class, $payment->id,
                ['workshop_invoice_id' => $lockedInvoice->id, 'work_order_id' => $lockedInvoice->work_order_id],
                ['paid_amount' => (string) $payment->paid_amount, 'payment_date' => (string) $payment->payment_date],
            );
            $this->outbox->record(
                $lockedInvoice->tenant_id, 'maintenance_memo.paid', WorkOrderExternalService::class, $lockedMemo->id,
                ['work_order_id' => $lockedMemo->work_order_id, 'workshop_invoice_id' => $lockedInvoice->id],
                ['memo_number' => $lockedMemo->memo_number],
            );

            return $payment;
        });
    }
}
