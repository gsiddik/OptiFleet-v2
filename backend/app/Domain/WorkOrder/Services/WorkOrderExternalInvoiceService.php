<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Partner\Models\Partner;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
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
}
