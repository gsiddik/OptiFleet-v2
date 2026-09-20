<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Partner\Models\Partner;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice" Phase 4/5: the Work Authorization Letter and the rest of the
 * External Work Order Invoice lifecycle actions. Every method starts by
 * lock-reading the invoice row and calling assertActionAllowed() — the
 * Section 5 action matrix is enforced here regardless of what the
 * frontend shows, so a direct/replayed call against an illegal state is
 * always rejected.
 */
class WorkOrderExternalInvoiceService
{
    public function __construct(
        private readonly WalNumberGenerator $walNumbers,
        private readonly WorkOrderExternalInvoiceFileService $files,
        private readonly WorkOrderTransitionService $transitions,
    ) {}

    /**
     * Section 6: snapshots the chosen vendor/vehicle/company data at generation time — a later
     * change to any of those master records must never alter an already-generated WAL. Per the
     * action matrix this is only reachable while work_authorization_status is NOT_GENERATED, so
     * it only ever runs once per invoice (re-finalizing after a Revise reuses the same invoice
     * row, but Revise itself is only allowed before the WAL is generated — see
     * ExternalWorkOrderService::revise()). wal_revision is incremented here regardless, so the
     * counter stays accurate if that gating is ever relaxed later.
     */
    public function generateAuthorization(WorkOrderExternalInvoice $invoice, string $partnerId, ?string $userId): WorkOrderExternalInvoice
    {
        return DB::transaction(function () use ($invoice, $partnerId, $userId) {
            $locked = WorkOrderExternalInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->assertActionAllowed('generate_authorization');

            $partner = Partner::query()->findOrFail($partnerId);
            abort_unless($partner->tenant_id === $locked->tenant_id, 404);
            if ($partner->partner_type !== 'EXTERNAL_WORKSHOP' || $partner->status !== 'ACTIVE') {
                throw new WorkOrderException('The selected workshop must be an active External Workshop partner.');
            }

            $workOrder = WorkOrder::query()->with('vehicle')->findOrFail($locked->work_order_id);
            $tenant = Tenant::query()->find($locked->tenant_id);
            $issueDate = now();
            $walNumber = $this->walNumbers->generate($locked->tenant_id, $issueDate);

            $locked->update([
                'wal_number' => $walNumber,
                'wal_issue_date' => $issueDate->toDateString(),
                'wal_workshop_partner_id' => $partner->id,
                'wal_workshop_name' => $partner->name,
                'wal_workshop_address' => $partner->address,
                'wal_workshop_pic' => $partner->contact_name,
                'wal_workshop_phone' => $partner->contact_phone,
                'wal_vehicle_unit_number' => $workOrder->vehicle?->registration_number,
                'wal_vehicle_registration_number' => $workOrder->vehicle?->registration_number,
                'wal_vehicle_make_model' => trim(($workOrder->vehicle?->brand ?? '').' '.($workOrder->vehicle?->model ?? '')),
                'wal_vehicle_odometer' => $workOrder->vehicle?->current_odometer,
                'wal_company_name' => $tenant?->legal_name ?: $tenant?->name,
                'wal_revision' => $locked->wal_revision + 1,
                'wal_generated_by' => $userId,
                'wal_generated_at' => $issueDate,
                'work_authorization_status' => 'GENERATED',
            ]);

            return $locked->fresh();
        });
    }

    public function deliver(WorkOrderExternalInvoice $invoice, ?string $userId): WorkOrderExternalInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $locked = WorkOrderExternalInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->assertActionAllowed('deliver');

            $locked->update([
                'status' => 'DELIVERED',
                'delivered_by' => $userId,
                'delivered_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function acknowledge(WorkOrderExternalInvoice $invoice, \Illuminate\Http\UploadedFile $file, ?string $userId): WorkOrderExternalInvoice
    {
        return DB::transaction(function () use ($invoice, $file, $userId) {
            $locked = WorkOrderExternalInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->assertActionAllowed('acknowledge');

            $this->files->upload($locked, 'ACKNOWLEDGEMENT', $file, $userId);

            $locked->update([
                'status' => 'IN_PROGRESS',
                'work_authorization_status' => 'ACKNOWLEDGED',
                'acknowledged_by' => $userId,
                'acknowledged_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Section 8: records that the External Workshop has finished the work — both the completed
     * Work Order copy and the vendor's own invoice are uploaded here, along with the invoice's
     * date/amount/payment term. Amount is validated positive using the established
     * BigDecimal/HALF_UP-scale-4 convention (never native float) for money.
     */
    public function complete(
        WorkOrderExternalInvoice $invoice,
        \Illuminate\Http\UploadedFile $completedWorkOrderFile,
        \Illuminate\Http\UploadedFile $vendorInvoiceFile,
        string $vendorInvoiceDate,
        string $vendorInvoiceAmount,
        string $paymentTerm,
        ?string $userId,
    ): WorkOrderExternalInvoice {
        $amount = BigDecimal::of($vendorInvoiceAmount)->toScale(4, RoundingMode::HALF_UP);
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new WorkOrderException('Vendor invoice amount must be greater than zero.');
        }

        return DB::transaction(function () use ($invoice, $completedWorkOrderFile, $vendorInvoiceFile, $vendorInvoiceDate, $amount, $paymentTerm, $userId) {
            $locked = WorkOrderExternalInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->assertActionAllowed('complete');

            $this->files->upload($locked, 'COMPLETED_WORK_ORDER', $completedWorkOrderFile, $userId);
            $this->files->upload($locked, 'VENDOR_INVOICE', $vendorInvoiceFile, $userId);

            $locked->update([
                'status' => 'BILLED',
                'vendor_invoice_date' => $vendorInvoiceDate,
                'vendor_invoice_amount' => (string) $amount,
                'payment_term' => $paymentTerm,
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Section 9: records payment to the vendor and, once settled, atomically closes the Work
     * Order (EXTERNAL -> CLOSED) in the same transaction — the two aggregates can never end up
     * out of sync (Invoice PAID with the Work Order still EXTERNAL, or vice versa).
     *
     * The source document does not describe a partial-payment flow, and instruction is explicit
     * not to assume one exists — so `paid_amount` must exactly equal the recorded vendor invoice
     * amount (compared as BigDecimal, not floats) or the settlement is rejected. This is flagged
     * as a business clarification: if partial/installment settlement is actually required, this
     * equality check is the one place to relax.
     *
     * Pre-existing compatibility fix (Section 13): WorkOrderClosureGuardService — a generic guard
     * that already ran for every WO closing, internal or External — rejects any close while a
     * Finding is still OPEN. Because External status rejects all Findings mutation (they cannot
     * be individually resolved once finalized), that guard would deadlock every External closure
     * with no way to satisfy it. Settlement is the one point where the External Workshop's work
     * is confirmed done (backed by the uploaded Completed Work Order + vendor invoice from
     * Complete), so it auto-resolves this Work Order's Findings immediately before closing —
     * this is a disclosed judgment call, not something the source document specifies.
     */
    public function settle(
        WorkOrderExternalInvoice $invoice,
        \Illuminate\Http\UploadedFile $paymentProofFile,
        string $paymentDate,
        string $paidAmount,
        ?string $userId,
    ): WorkOrderExternalInvoice {
        return DB::transaction(function () use ($invoice, $paymentProofFile, $paymentDate, $paidAmount, $userId) {
            $locked = WorkOrderExternalInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->assertActionAllowed('settle');

            $paid = BigDecimal::of($paidAmount)->toScale(4, RoundingMode::HALF_UP);
            $invoiceAmount = BigDecimal::of($locked->vendor_invoice_amount ?? '0')->toScale(4, RoundingMode::HALF_UP);
            if (! $paid->isEqualTo($invoiceAmount)) {
                throw new WorkOrderException(
                    "Paid amount ({$paid}) must exactly match the vendor invoice amount ({$invoiceAmount}) — partial settlement is not supported."
                );
            }

            $this->files->upload($locked, 'PAYMENT_PROOF', $paymentProofFile, $userId);

            $locked->update([
                'status' => 'PAID',
                'payment_date' => $paymentDate,
                'paid_amount' => (string) $paid,
                'settled_by' => $userId,
                'settled_at' => now(),
            ]);

            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($locked->work_order_id);
            if ($workOrder->status === 'EXTERNAL') {
                \App\Domain\WorkOrder\Models\WorkOrderFinding::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('status', 'OPEN')
                    ->update([
                        'status' => 'RESOLVED',
                        'resolution_notes' => "Resolved automatically: External Workshop work confirmed complete and settled (WAL {$locked->wal_number}).",
                        'resolved_by' => $userId,
                        'resolved_at' => now(),
                    ]);

                $this->transitions->transition($workOrder, 'CLOSED');
            }

            return $locked->fresh();
        });
    }
}
