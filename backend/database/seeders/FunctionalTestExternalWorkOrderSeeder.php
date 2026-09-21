<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Tenant;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Partner\Models\Partner;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Domain\WorkOrder\Services\ExternalWorkOrderService;
use App\Domain\WorkOrder\Services\WorkOrderExternalInvoiceService;
use App\Domain\WorkOrder\Services\WorkOrderExternalServiceService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\WorkOrder\Services\WorkshopInvoiceService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * External Work Order / WAL / Invoice / Settlement scenario matrix
 * (Section 33-36, E1-E5) — Findings are the basis for External WO per the
 * confirmed rule (Section 32/57); Jobs are never used. Each scenario is a
 * strict superset of the previous one's steps, all through
 * `ExternalWorkOrderService`/`WorkOrderExternalInvoiceService` — never a
 * status inserted directly. "Workshop Invoice" in the task brief maps to
 * this chain reaching BILLED/PAID (see the pre-implementation report's
 * naming reconciliation); a separate, genuinely distinct `WorkshopInvoice`
 * (Maintenance Memo) scenario is included too for full coverage of that
 * unrelated pre-existing feature.
 */
class FunctionalTestExternalWorkOrderSeeder
{
    public function run(Tenant $tenant, object $ops, Carbon $referenceDate): void
    {
        $workshopManagerId = User::query()->where('email', 'ft.workshopmanager@optifleet.test')->value('id');
        $groups = ComponentGroup::query()->whereIn('code', ['CG-SUSP', 'CG-TRANS', 'CG-ENGINE', 'CG-AXLE', 'CG-ELEC'])->whereNull('tenant_id')->pluck('id', 'code');
        $externalWorkshop = Partner::query()->where('tenant_id', $tenant->id)->where('code', 'TEST-EXT-WS-A')->firstOrFail();

        $external = app(ExternalWorkOrderService::class);
        $invoices = app(WorkOrderExternalInvoiceService::class);
        $workOrders = app(WorkOrderService::class);

        // E1 — Ready for External Processing: Draft, External mode, Findings recorded, not yet finalized.
        $wo1 = $this->existing($tenant, 'FT-EWO-PRE-FINALIZE');
        if (! $wo1) {
            $wo1 = $workOrders->create($ops->vehicles['CAR_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
                'complaint' => '[FT-EWO-PRE-FINALIZE] Suspension noise, referred to external workshop.',
            ], null);
            $wo1 = $external->markExternalMode($wo1);
            $external->addFinding($wo1, ['component_group_id' => $groups['CG-SUSP'], 'severity' => 'MEDIUM', 'description' => 'Suspension bushing worn, requires external specialist.'], $workshopManagerId);
        }

        // E2 — External WO Created: finalized, invoice NEW_EXTERNAL_WO (Revise still allowed).
        $wo2 = $this->existing($tenant, 'FT-EWO-PRE-WAL');
        if (! $wo2) {
            $wo2 = $workOrders->create($ops->vehicles['CAR_2'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
                'complaint' => '[FT-EWO-PRE-WAL] Transmission issue, referred to external workshop.',
            ], null);
            $wo2 = $external->markExternalMode($wo2);
            $external->addFinding($wo2, ['component_group_id' => $groups['CG-TRANS'], 'severity' => 'HIGH', 'description' => 'Transmission slipping, requires external specialist diagnosis.'], $workshopManagerId);
            $wo2 = $external->finalize($wo2, $workshopManagerId);
        }

        // E3 — WAL Delivered: WAL generated + delivered, invoice DELIVERED (Revise now blocked).
        $wo3 = $this->existing($tenant, 'FT-EWO-WAL-DELIVERED');
        if (! $wo3) {
            $wo3 = $workOrders->create($ops->vehicles['TRUCK_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
                'complaint' => '[FT-EWO-WAL-DELIVERED] Turbocharger failure, referred to external workshop.',
            ], null);
            $wo3 = $external->markExternalMode($wo3);
            $external->addFinding($wo3, ['component_group_id' => $groups['CG-ENGINE'], 'severity' => 'HIGH', 'description' => 'Turbocharger seized, requires external overhaul.'], $workshopManagerId);
            $wo3 = $external->finalize($wo3, $workshopManagerId);
            $invoice3 = WorkOrderExternalInvoice::query()->where('work_order_id', $wo3->id)->firstOrFail();
            $invoice3 = $invoices->generateAuthorization($invoice3, $externalWorkshop->id, $workshopManagerId);
            $invoices->deliver($invoice3, $workshopManagerId);
        }

        // E4 — Workshop Invoice open: acknowledged + vendor invoice completed/recorded (BILLED, awaiting payment).
        $wo4 = $this->existing($tenant, 'FT-EWO-INVOICE-BILLED');
        if (! $wo4) {
            $wo4 = $workOrders->create($ops->vehicles['TRUCK_2'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
                'complaint' => '[FT-EWO-INVOICE-BILLED] Differential overhaul, referred to external workshop.',
            ], null);
            $wo4 = $external->markExternalMode($wo4);
            $external->addFinding($wo4, ['component_group_id' => $groups['CG-AXLE'], 'severity' => 'HIGH', 'description' => 'Differential gear noise, requires external overhaul.'], $workshopManagerId);
            $wo4 = $external->finalize($wo4, $workshopManagerId);
            $invoice4 = WorkOrderExternalInvoice::query()->where('work_order_id', $wo4->id)->firstOrFail();
            $invoice4 = $invoices->generateAuthorization($invoice4, $externalWorkshop->id, $workshopManagerId);
            $invoice4 = $invoices->deliver($invoice4, $workshopManagerId);
            $invoice4 = $invoices->acknowledge($invoice4, $this->fakePdf('acknowledgement.pdf'), $workshopManagerId);
            $invoices->complete(
                $invoice4,
                $this->fakePdf('completed-work-order.pdf'),
                $this->fakePdf('vendor-invoice.pdf'),
                $referenceDate->copy()->subDays(2)->toDateString(),
                '4500000.00',
                'NET_14',
                $workshopManagerId,
            );
        }

        // E5 — Settled: exact-match payment recorded, invoice PAID, Work Order auto-CLOSED.
        $wo5 = $this->existing($tenant, 'FT-EWO-SETTLED');
        if (! $wo5) {
            $wo5 = $workOrders->create($ops->vehicles['CAR_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
                'complaint' => '[FT-EWO-SETTLED] Aircon compressor replaced externally and settled.',
            ], null);
            $wo5 = $external->markExternalMode($wo5);
            $external->addFinding($wo5, ['component_group_id' => $groups['CG-ELEC'], 'severity' => 'MEDIUM', 'description' => 'AC compressor failure, requires external replacement.'], $workshopManagerId);
            $wo5 = $external->finalize($wo5, $workshopManagerId);
            $invoice5 = WorkOrderExternalInvoice::query()->where('work_order_id', $wo5->id)->firstOrFail();
            $invoice5 = $invoices->generateAuthorization($invoice5, $externalWorkshop->id, $workshopManagerId);
            $invoice5 = $invoices->deliver($invoice5, $workshopManagerId);
            $invoice5 = $invoices->acknowledge($invoice5, $this->fakePdf('acknowledgement.pdf'), $workshopManagerId);
            $invoice5 = $invoices->complete(
                $invoice5,
                $this->fakePdf('completed-work-order.pdf'),
                $this->fakePdf('vendor-invoice.pdf'),
                $referenceDate->copy()->subDays(3)->toDateString(),
                '2100000.00',
                'NET_14',
                $workshopManagerId,
            );
            $invoices->settle($invoice5, $this->fakePdf('payment-proof.pdf'), $referenceDate->copy()->subDay()->toDateString(), '2100000.00', $workshopManagerId);
        }

        $this->seedWorkshopInvoiceBonusScenario($tenant, $ops, $workshopManagerId, $referenceDate);
    }

    /**
     * Bonus coverage (Section 35) of the genuinely distinct `WorkshopInvoice`
     * feature: a Maintenance Memo for external/3rd-party service on an
     * otherwise INTERNAL Work Order, billed and paid.
     */
    private function seedWorkshopInvoiceBonusScenario(Tenant $tenant, object $ops, ?string $userId, Carbon $referenceDate): void
    {
        $wo = $this->existing($tenant, 'FT-WO-MAINTENANCE-MEMO');
        if ($wo) {
            return;
        }

        $towingPartner = Partner::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-VND-TOWING'],
            ['name' => '[TEST] Towing & 3rd-Party Service', 'partner_type' => 'TOWING_PROVIDER', 'contact_name' => 'Joko Prasetyo', 'contact_phone' => '021-5550003', 'payment_terms' => 'NET_14', 'status' => 'ACTIVE']
        );

        $workOrders = app(WorkOrderService::class);
        $wo = $workOrders->create($ops->vehicles['TRUCK_1'], [
            'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
            'complaint' => '[FT-WO-MAINTENANCE-MEMO] Breakdown recovery towed to workshop, billed and paid.',
        ], null);
        $wo = $workOrders->submit($wo);
        $wo = $workOrders->approve($wo);
        $wo = $workOrders->assign($wo);

        $memoService = app(WorkOrderExternalServiceService::class);
        $memo = $memoService->create($wo, $towingPartner, [
            'description' => 'Flatbed towing from breakdown location to workshop.',
            'priority' => 'HIGH', 'reference_number' => 'TEST-TOW-REQ-0001',
        ], $userId);
        $memo = $memoService->complete($memo, $userId);

        $invoiceService = app(WorkshopInvoiceService::class);
        $invoice = $invoiceService->record($memo, [
            'external_invoice_number' => 'TEST-TOW-INV-0001',
            'invoice_date' => $referenceDate->copy()->subDays(5)->toDateString(),
            'total_amount' => '850000.00',
            'invoice_attachment_url' => 'test-fixtures/workshop-invoices/tow-invoice-0001.pdf',
        ], $userId);

        $invoiceService->recordPayment($invoice, [
            'payment_date' => $referenceDate->copy()->subDays(1)->toDateString(),
            'paid_amount' => '850000.00',
            'payment_method' => 'BANK_TRANSFER',
            'evidence_url' => 'test-fixtures/workshop-invoices/tow-payment-proof-0001.pdf',
        ], $userId);
    }

    private function existing(Tenant $tenant, string $scenarioId): ?WorkOrder
    {
        return WorkOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('complaint', 'like', "[{$scenarioId}]%")
            ->first();
    }

    private function fakePdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 50, 'application/pdf');
    }
}
