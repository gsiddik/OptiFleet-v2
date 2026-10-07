<?php

namespace Database\Seeders;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Breakdown\Services\BreakdownService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Services\ComponentAssetException;
use App\Domain\ComponentAsset\Services\ComponentAssetRegisterService;
use App\Domain\ComponentAsset\Services\ComponentAssetService;
use App\Domain\Dashboard\Models\MechanicPerformanceBaseline;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Procurement\Services\VendorInvoicePaymentService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\ExternalWorkOrderService;
use App\Domain\WorkOrder\Services\WorkOrderExternalInvoiceService;
use App\Domain\WorkOrder\Services\WorkOrderExternalServiceService;
use App\Domain\WorkOrder\Services\WorkOrderPartRequestService;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderRemovedComponentService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\WorkOrder\Services\WorkshopInvoiceService;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Services\MechanicAssignmentService;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Demo layer only (never production): 12 full months + the running month of history for the ALPHA
 * tenant so every Tenant Dashboard trend has something to show. Everything goes through the
 * application services (Purchase Order → Goods Receipt → vendor invoice → payment; Work Order
 * lifecycle with Part Request issue / consume; maintenance memo → Service Invoice → payment; External
 * Work Order → WAL → bill → settle; breakdown report → resolve; stock transfer dispatch), each step run
 * under a controlled clock (Carbon::setTestNow) at its historical time — no row is written around a
 * service. One month (7 months ago) is deliberately left without transactions.
 *
 * Scenarios: completed / waiting-for-part / cancelled Work Orders; unpaid (not due, overdue) and paid
 * vendor invoices; paid, unpaid and cancelled-then-re-recorded Service Invoices; billed and settled External WO invoices; resolved
 * breakdowns + one open immobilized breakdown; a transfer in transit for 10 days; a low-stock part.
 * Partially paid invoices are not seeded: the application only allows one full payment per invoice.
 *
 * Operations KPIs (work intervals are recorded by the Work Order transitions themselves): dedicated
 * demo mechanics per branch with hourly rates (one without a rate, one rate raised mid-history, so
 * earlier assignments keep their snapshot), assistants, a mid-work change of PRIMARY mechanic,
 * waiting-for-part and waiting-for-QC gaps, rework cycles (one Work Order with two), a monthly
 * labor-only corrective Work Order in Jakarta (enough samples for Mechanic Performance), one Work
 * Order still in progress, a component moved from one vehicle to another, thresholds left unset /
 * intentionally 0 / set, and a demo-only CORRECTIVE baseline (PREVENTIVE left unset). Production
 * seeders never set a baseline or a threshold.
 *
 * Idempotent: vehicles are matched by registration number; the history is created once (marker in
 * the Work Order complaint "[DASH-DEMO]"); a re-run leaves everything unchanged.
 */
class DashboardDemoSeeder extends Seeder
{
    public const MARKER = '[DASH-DEMO]';

    /** Months ago without any transaction (an empty month on every trend). */
    public const EMPTY_MONTHS_AGO = 7;

    private Tenant $tenant;

    private User $admin;

    private CarbonImmutable $realNow;

    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'ALPHA')->first();
        $admin = User::query()->where('email', 'alpha.admin@optifleet.test')->first();
        if (! $tenant || ! $admin) {
            return;
        }
        $this->tenant = $tenant;
        $this->admin = $admin;
        $context = app(TenantContext::class);
        $previousTenant = $context->tenantId();
        $context->setTenantId($tenant->id);
        $context->setUser($admin);
        // The clock is controllable: a caller that froze time (Carbon::setTestNow) seeds relative to it
        // and gets its frozen time back afterwards.
        $callerNow = Carbon::getTestNow();
        $this->realNow = CarbonImmutable::now();
        try {
            $vehicles = $this->vehicles();
            if (WorkOrder::query()->where('tenant_id', $tenant->id)->where('complaint', 'like', self::MARKER.'%')->exists()) {
                return;
            }
            $this->history($vehicles);
            $this->currentState($vehicles);
        } finally {
            Carbon::setTestNow($callerNow);
            $context->setUser(null);
            $context->setTenantId($previousTenant);
        }
    }

    // ------------------------------------------------------------------ setup

    /** Three dedicated demo vehicles (Jakarta, Bandung, Semarang), so other demo scenarios stay untouched. */
    private function vehicles(): array
    {
        $category = VehicleCategory::query()->whereNull('tenant_id')->where('code', 'VC-TRUCK')->first() ?? VehicleCategory::query()->whereNull('tenant_id')->firstOrFail();
        $defs = ['jkt' => ['B 4101 ALP', 'ALPHA-JKT'], 'bdg' => ['D 4102 ALP', 'ALPHA-BDG'], 'smg' => ['H 4103 ALP', 'ALPHA-SMG']];
        $vehicles = [];
        foreach ($defs as $key => [$reg, $branchCode]) {
            $branch = Branch::query()->where('tenant_id', $this->tenant->id)->where('code', $branchCode)->firstOrFail();
            $vehicles[$key] = Vehicle::query()->firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'registration_number' => $reg],
                ['branch_id' => $branch->id, 'vehicle_category_id' => $category->id, 'brand' => 'Isuzu', 'model' => 'Elf NMR', 'vehicle_type' => 'TRUCK',
                    'year' => 2022, 'fuel_type' => 'DIESEL', 'transmission_type' => 'MANUAL', 'current_odometer' => 50000,
                    'status' => 'ACTIVE', 'operational_status' => 'AVAILABLE']
            );
        }

        return $vehicles;
    }

    private function at(CarbonImmutable $time): void
    {
        Carbon::setTestNow($time);
    }

    /** The workshop of the vehicle's own branch, so branch-scoped users see their branch's Work Orders. */
    private function workshop(Vehicle $vehicle): Workshop
    {
        return Workshop::query()->where('tenant_id', $this->tenant->id)->where('branch_id', $vehicle->branch_id)->orderBy('code')->firstOrFail();
    }

    /** Demo mechanics per branch workshop: [code, name, worker type, hourly rate (null = not set)]. */
    private const MECHANICS = [
        'jkt' => [['DASH-JKT-M1', 'Rudi Hartono', 'LEAD_MECHANIC', 55000], ['DASH-JKT-M2', 'Agus Salim', 'MECHANIC', 45000]],
        'bdg' => [['DASH-BDG-M1', 'Asep Sunandar', 'LEAD_MECHANIC', 50000], ['DASH-BDG-M2', 'Ujang Permana', 'MECHANIC', null]],
        'smg' => [['DASH-SMG-M1', 'Slamet Riyadi', 'LEAD_MECHANIC', 48000], ['DASH-SMG-M2', 'Joko Purnomo', 'MECHANIC', 42000]],
    ];

    /** @return array{0: Worker, 1: Worker} lead + second mechanic of the vehicle's workshop (created once; a re-run never resets a rate). */
    private function mechanics(Vehicle $vehicle): array
    {
        $key = array_search($vehicle->registration_number, ['jkt' => 'B 4101 ALP', 'bdg' => 'D 4102 ALP', 'smg' => 'H 4103 ALP'], true);
        $workshop = $this->workshop($vehicle);

        return array_map(fn ($def) => Worker::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'employee_code' => $def[0]],
            ['name' => $def[1], 'branch_id' => $vehicle->branch_id, 'workshop_id' => $workshop->id, 'worker_type' => $def[2], 'status' => 'ACTIVE', 'hourly_rate' => $def[3]]
        ), self::MECHANICS[$key]);
    }

    private function assignMechanic(WorkOrder $wo, Worker $worker, string $role): void
    {
        app(MechanicAssignmentService::class)->assign($wo->fresh(), $worker, $role, null, $this->admin->id);
    }

    private function warehouse(string $code = 'ALPHA-JKT-WH1'): Warehouse
    {
        return Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail();
    }

    private function product(string $name): Product
    {
        return Product::query()->where('tenant_id', $this->tenant->id)->where('name', $name)->firstOrFail();
    }

    private function vendor(string $code): Partner
    {
        return Partner::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail();
    }

    /** ALPHA has no external-workshop partner in the base demo; one is added (idempotent) for external WOs. */
    private function externalWorkshop(): Partner
    {
        return Partner::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => 'VND-EXTWS'],
            ['name' => 'PT Bengkel Mitra Diesel', 'partner_type' => 'EXTERNAL_WORKSHOP', 'contact_name' => 'Hendra Wijaya', 'contact_phone' => '024-7600123', 'payment_terms' => 'NET_30', 'status' => 'ACTIVE']
        );
    }

    private function pdf(string $name): UploadedFile
    {
        return DemoQuotationDocument::make($name, 'Dashboard demo document', $name.'.pdf');
    }

    // ------------------------------------------------------------------ history

    private function history(array $vehicles): void
    {
        $rotation = ['jkt', 'bdg', 'smg'];
        for ($ago = 12; $ago >= 0; $ago--) {
            if ($ago === self::EMPTY_MONTHS_AGO) {
                continue;
            }
            $start = $this->realNow->startOfMonth()->subMonthsNoOverflow($ago)->addDays(1)->setTime(1, 0);
            $latest = $ago === 0 ? $this->realNow->subHours(2) : null;
            $time = fn (int $days, int $hours = 0) => $latest !== null
                ? $start->addDays($days)->addHours($hours)->min($latest)
                : $start->addDays($days)->addHours($hours);
            if ($ago === 0 && $start->greaterThan($this->realNow->subHours(3))) {
                continue; // the running month just started: nothing yet
            }

            $this->procurement($ago, $time);
            if ($ago === 6) {
                // Rate raised: assignments made before keep their 55,000 snapshot.
                $this->at($time(8));
                $this->mechanics($vehicles['jkt'])[0]->update(['hourly_rate' => 60000]);
            }
            $vehicle = $vehicles[$rotation[$ago % 3]];
            $this->internalWorkOrder($vehicle, $ago, $time);
            $this->laborOnlyWorkOrder($vehicles['jkt'], $ago, $time);
            if ($ago % 4 === 0) {
                $this->externalWorkOrder($vehicles['smg'], $ago, $time);
            }
            if ($ago > 0) {
                $this->resolvedBreakdown($vehicles[$rotation[($ago + 1) % 3]], $ago, $time);
            }
        }
    }

    /** PO issued → received (late every third month) against a vendor invoice; invoices ≥ 4 months old are paid. */
    private function procurement(int $ago, callable $time): void
    {
        $service = app(PurchaseOrderService::class);
        $vendor = $this->vendor($ago % 2 === 0 ? 'VND-SINAR' : 'VND-ANDALAN');
        $warehouse = $this->warehouse();
        $this->at($time(0));
        $po = $service->create($vendor, $warehouse, ['expected_delivery_date' => $time(5)->toDateString(), 'notes' => self::MARKER.' monthly replenishment'], [
            ['product_id' => $this->product('Brake Pad Set (Front)')->id, 'quantity_ordered' => 6, 'unit_price' => 350000 + $ago * 5000],
            ['product_id' => $this->product('Engine Oil Filter')->id, 'quantity_ordered' => 12, 'unit_price' => 85000],
        ], $this->admin->id);
        $po = $service->transition($service->approve($service->transition($po, 'SUBMITTED'), $this->admin->id), 'ISSUED');

        $receivedAt = $time($ago % 3 === 0 ? 8 : 3);
        if ($receivedAt->greaterThan($this->realNow->subHour())) {
            return; // not delivered yet
        }
        $this->at($receivedAt);
        $po = PurchaseOrder::query()->with('items')->findOrFail($po->id);
        $number = 'DASH-INV-'.$receivedAt->format('Ym');
        $receipt = app(GoodsReceiptService::class)->post($po, $warehouse, $po->items->map(fn ($item) => [
            'purchase_order_item_id' => $item->id, 'quantity_accepted' => (float) $item->quantity_ordered - ($ago === 5 ? 1 : 0), 'quantity_rejected' => $ago === 5 ? 1 : 0,
        ])->all(), $this->admin->id, null, [
            'mode' => 'NEW', 'vendor_invoice_number' => $number, 'vendor_invoice_date' => $receivedAt->toDateString(),
            'amount' => (string) $po->total, 'terms_of_payment_days' => 30, 'document' => $this->pdf($number),
        ]);
        if ($ago >= 4) {
            $invoice = VendorInvoiceReference::query()->findOrFail($receipt->vendor_invoice_reference_id);
            $this->at($receivedAt->addDays(25));
            app(VendorInvoicePaymentService::class)->pay($invoice, $receivedAt->addDays(25)->toDateString(), (string) $invoice->amount, $this->pdf($number.'-PAID'), $this->admin->id);
        }
    }

    /**
     * Full internal Work Order with consumed parts. Timeline (day 9 of the month): start 01:00 with the
     * branch lead (+ an assistant in odd months; the PRIMARY changes mid-work when ago % 5 = 2), part
     * request 03:00 issued after 1 h — or, every third month, the Work Order waits for the part until
     * 07:00 —, submitted to QC 09:00, completed the next day; when ago % 4 = 1 QC sends it back to rework
     * (two cycles 9 months ago). Every third month also a maintenance memo → Service Invoice.
     */
    private function internalWorkOrder(Vehicle $vehicle, int $ago, callable $time): void
    {
        $workOrders = app(WorkOrderService::class);
        [$lead, $second] = $this->mechanics($vehicle);
        $this->at($time(9));
        $wo = $workOrders->create($vehicle->fresh(), [
            'workshop_id' => $this->workshop($vehicle)->id, 'maintenance_type' => $ago % 2 === 0 ? 'PREVENTIVE' : 'CORRECTIVE', 'priority' => 'MEDIUM',
            'complaint' => self::MARKER.' periodic service '.$time(9)->format('Y-m'),
        ], $this->admin->id);
        $wo = $workOrders->assign($workOrders->approve($workOrders->submit($wo)));
        $this->assignMechanic($wo, $lead, 'PRIMARY');
        if ($ago % 2 === 1) {
            $this->assignMechanic($wo, $second, 'ASSISTANT');
        }
        DemoWorkspaceAssignment::approve($wo, $this->admin->id, null, $time(9));
        $this->at($time(9, 1));
        $wo = $workOrders->start($workOrders->schedule($wo->fresh()));

        $this->at($time(9, 2));
        $requests = app(WorkOrderPartRequestService::class);
        $request = $requests->request($wo->fresh(), [
            ['product_id' => $this->product('Brake Pad Set (Front)')->id, 'quantity_requested' => 1],
            ['product_id' => $this->product('Engine Oil Filter')->id, 'quantity_requested' => 2],
        ], null, $this->admin->id);
        $waits = $ago % 3 === 0;
        if ($waits) {
            $workOrders->waitForPart($wo->fresh());
        }
        $this->at($time(9, $waits ? 6 : 3));
        $request = $requests->issue($requests->approve($request, null, $this->admin->id, null), $this->warehouse(), $this->admin->id);
        if ($waits) {
            $workOrders->resume($wo->fresh());
        }
        foreach ($request->items as $item) {
            app(WorkOrderPartService::class)->consume(WorkOrderPlannedPart::query()->findOrFail($item->planned_part_id), null, $this->admin->id);
        }
        if ($ago % 5 === 2) {
            $this->at($time(9, 7));
            $this->assignMechanic($wo, $second, 'PRIMARY'); // hand-over: the lead's assignment ends here
        }

        $memo = null;
        if ($ago % 3 === 1) {
            $memos = app(WorkOrderExternalServiceService::class);
            $memo = $memos->create($wo->fresh(), $this->vendor('VND-MITRA'), ['description' => 'Brake drum machining (external)', 'priority' => 'MEDIUM'], $this->admin->id);
            $memo = $memos->complete($memo, $this->admin->id);
        }

        $this->at($time(9, 8));
        $workOrders->submitToQc($wo->fresh());
        $cycles = $ago === 9 ? 2 : ($ago % 4 === 1 ? 1 : 0);
        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            $this->at($time(10, 1 + 4 * $cycle));
            $workOrders->rework($wo->fresh());
            $this->at($time(10, 2 + 4 * $cycle));
            $workOrders->resume($wo->fresh());
            $this->at($time(10, 4 + 4 * $cycle));
            $workOrders->submitToQc($wo->fresh());
        }
        $this->at($time(10, 12));
        $workOrders->complete($wo->fresh());

        if ($memo !== null) {
            $this->at($time(11));
            $invoices = app(WorkshopInvoiceService::class);
            $number = 'DASH-SI-'.$time(11)->format('Ym');
            if ($ago === 4) {
                // Recorded with a wrong amount, cancelled through maker-checker, then re-recorded: the
                // cancelled document must never reach Service Cost or payables.
                $wrong = $invoices->record($memo, [
                    'external_invoice_number' => $number.'-X', 'invoice_date' => $time(11)->toDateString(), 'due_date' => $time(41)->toDateString(),
                    'total_amount' => '9999999', 'invoice_attachment_url' => 'demo/workshop-invoices/'.$number.'-X.pdf',
                ], $this->admin->id);
                $cancellation = $invoices->requestCancellation($wrong, 'Wrong amount keyed in.', $this->admin->id);
                $checker = User::query()->where('email', 'alpha.workshopmanager@optifleet.test')->firstOrFail();
                $invoices->decideCancellation($cancellation, 'APPROVE', $checker->id, 'Re-record with the vendor amount.');
                $memo = $memo->fresh();
            }
            $invoice = $invoices->record($memo, [
                'external_invoice_number' => $number, 'invoice_date' => $time(11)->toDateString(), 'due_date' => $time(41)->toDateString(),
                'total_amount' => (string) (550000 + 25000 * $ago), 'invoice_attachment_url' => 'demo/workshop-invoices/'.$number.'.pdf',
            ], $this->admin->id);
            if ($ago >= 2) {
                $this->at($time(20));
                $invoices->recordPayment($invoice, ['payment_date' => $time(20)->toDateString(), 'paid_amount' => (string) $invoice->total_amount,
                    'payment_method' => 'BANK_TRANSFER', 'evidence_url' => 'demo/workshop-invoices/'.$number.'-paid.pdf'], $this->admin->id);
            }
        }
    }

    /** Monthly labor-only corrective Work Order in Jakarta by the lead mechanic: 4–7 working hours, so Mechanic Performance has samples. */
    private function laborOnlyWorkOrder(Vehicle $vehicle, int $ago, callable $time): void
    {
        $workOrders = app(WorkOrderService::class);
        $this->at($time(14));
        $wo = $workOrders->create($vehicle->fresh(), [
            'workshop_id' => $this->workshop($vehicle)->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
            'complaint' => self::MARKER.' electrical fault diagnosis '.$time(14)->format('Y-m'),
        ], $this->admin->id);
        $wo = $workOrders->assign($workOrders->approve($workOrders->submit($wo)));
        $this->assignMechanic($wo, $this->mechanics($vehicle)[0], 'PRIMARY');
        DemoWorkspaceAssignment::approve($wo, $this->admin->id, null, $time(14), 8);
        $this->at($time(14, 1));
        $wo = $workOrders->start($workOrders->schedule($wo->fresh()));
        $this->at($time(14, 1 + 4 + $ago % 4));
        $workOrders->submitToQc($wo->fresh());
        $this->at($time(14, 7 + 4));
        $workOrders->complete($wo->fresh());
    }

    /** External WO: WAL → delivered → acknowledged → billed; settled when older than 6 months. */
    private function externalWorkOrder(Vehicle $vehicle, int $ago, callable $time): void
    {
        $workOrders = app(WorkOrderService::class);
        $external = app(ExternalWorkOrderService::class);
        $invoices = app(WorkOrderExternalInvoiceService::class);
        $group = ComponentGroup::query()->whereNull('tenant_id')->where('code', 'CG-ENGINE')->value('id');
        $this->at($time(12));
        $wo = $workOrders->create($vehicle->fresh(), [
            'workshop_id' => $this->workshop($vehicle)->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
            'complaint' => self::MARKER.' turbocharger overhaul at an external workshop',
        ], $this->admin->id);
        $wo = $external->markExternalMode($wo);
        $external->addFinding($wo, ['component_group_id' => $group, 'severity' => 'HIGH', 'description' => 'Turbocharger worn, external overhaul.'], $this->admin->id);
        $wo = $external->finalize($wo, $this->admin->id);
        $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $wo->id)->firstOrFail();
        $invoice = $invoices->deliver($invoices->generateAuthorization($invoice, $this->externalWorkshop()->id, $this->admin->id), $this->admin->id);
        $invoice = $invoices->acknowledge($invoice, UploadedFile::fake()->create('acknowledgement.pdf', 50, 'application/pdf'), $this->admin->id);
        $this->at($time(15));
        $invoice = $invoices->complete($invoice, UploadedFile::fake()->create('completed.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('vendor-invoice.pdf', 50, 'application/pdf'), $time(15)->toDateString(), (string) (4200000 + 100000 * $ago), 'NET 30', $this->admin->id);
        if ($ago >= 6) {
            $this->at($time(30));
            $invoices->settle($invoice, UploadedFile::fake()->create('payment.pdf', 50, 'application/pdf'), $time(30)->toDateString(), (string) $invoice->vendor_invoice_amount, $this->admin->id);
        }
    }

    private function resolvedBreakdown(Vehicle $vehicle, int $ago, callable $time): void
    {
        $service = app(BreakdownService::class);
        $this->at($time(18));
        $breakdown = $service->report($vehicle->fresh(), ['location' => 'Toll road', 'severity' => ['MINOR', 'MAJOR', 'IMMOBILIZED'][$ago % 3],
            'description' => self::MARKER.' roadside stop'], $this->admin->id);
        $breakdown = $service->transition($service->transition($breakdown, 'VERIFIED'), 'ASSESSED');
        $this->at($time(19));
        $service->transition($breakdown, 'RESOLVED', 'Fixed on site.');
    }

    // ------------------------------------------------------------------ current state

    private function currentState(array $vehicles): void
    {
        $workOrders = app(WorkOrderService::class);

        // A Work Order waiting for parts for 9 days (part request not yet approved).
        $this->at($this->realNow->subDays(9));
        $wo = $workOrders->create($vehicles['jkt']->fresh(), ['workshop_id' => $this->workshop($vehicles['jkt'])->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
            'complaint' => self::MARKER.' clutch replacement, waiting for parts'], $this->admin->id);
        $wo = $workOrders->assign($workOrders->approve($workOrders->submit($wo)));
        DemoWorkspaceAssignment::approve($wo, $this->admin->id, null, $this->realNow->subDays(9));
        $wo = $workOrders->start($workOrders->schedule($wo->fresh()));
        app(WorkOrderPartRequestService::class)->request($wo->fresh(), [['product_id' => $this->product('Brake Pad Set (Front)')->id, 'quantity_requested' => 2]], null, $this->admin->id);
        $workOrders->waitForPart($wo->fresh());

        // A Work Order in progress for 3 hours (open work interval: labor counted up to now).
        $this->at($this->realNow->subHours(3));
        $running = $workOrders->create($vehicles['jkt']->fresh(), ['workshop_id' => $this->workshop($vehicles['jkt'])->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
            'complaint' => self::MARKER.' air brake leak, work in progress'], $this->admin->id);
        $running = $workOrders->assign($workOrders->approve($workOrders->submit($running)));
        $this->assignMechanic($running, $this->mechanics($vehicles['jkt'])[1], 'PRIMARY');
        DemoWorkspaceAssignment::approve($running, $this->admin->id, null, $this->realNow->subHours(3), 6);
        $workOrders->start($workOrders->schedule($running->fresh()));

        // A cancelled Work Order (no cost).
        $this->at($this->realNow->subDays(4));
        $cancelled = $workOrders->create($vehicles['bdg']->fresh(), ['workshop_id' => $this->workshop($vehicles['bdg'])->id, 'maintenance_type' => 'INSPECTION', 'priority' => 'LOW',
            'complaint' => self::MARKER.' inspection cancelled by the customer'], $this->admin->id);
        $workOrders->cancel($workOrders->submit($cancelled));

        // An open immobilized breakdown (30 h).
        $this->at($this->realNow->subHours(30));
        app(BreakdownService::class)->report($vehicles['bdg']->fresh(), ['location' => 'Cipularang KM 97', 'severity' => 'IMMOBILIZED',
            'description' => self::MARKER.' engine seized'], $this->admin->id);

        // A transfer in transit for 10 days.
        if (! StockTransfer::query()->where('tenant_id', $this->tenant->id)->where('notes', self::MARKER.' in transit')->exists()) {
            $transfers = app(StockTransferService::class);
            $this->at($this->realNow->subDays(10));
            $transfer = $transfers->create($this->warehouse(), $this->warehouse('ALPHA-BDG-WH1'), [['product_id' => $this->product('Engine Oil Filter')->id, 'quantity' => 3]], $this->admin->id);
            $transfer->update(['notes' => self::MARKER.' in transit']);
            $transfer = $transfers->transition($transfers->transition($transfers->transition($transfer, 'REQUESTED'), 'APPROVED'), 'PREPARED');
            $transfers->transition($transfers->dispatch($transfer, $this->admin->id), 'IN_TRANSIT');
        }

        $this->movedComponent($vehicles);
        $this->serializedInstallations($vehicles);
        $this->legacyHistoryWorkOrders($vehicles);

        // Thresholds (stock settings, as set on the warehouse stock screen): brake pads below their
        // reorder point in Jakarta; oil filters in Jakarta intentionally 0 (never "low", but set);
        // every other row is left unset ("threshold not set", never assumed 0).
        Carbon::setTestNow();
        WarehouseStock::query()->where('warehouse_id', $this->warehouse()->id)->where('product_id', $this->product('Brake Pad Set (Front)')->id)
            ->update(['reorder_point' => 80]);
        WarehouseStock::query()->where('warehouse_id', $this->warehouse()->id)->where('product_id', $this->product('Engine Oil Filter')->id)
            ->update(['reorder_point' => 0]);

        // Demo-only Mechanic Performance baseline: CORRECTIVE set, PREVENTIVE deliberately left unset.
        MechanicPerformanceBaseline::query()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'maintenance_type' => 'CORRECTIVE'],
            ['baseline_hours' => '6.00', 'updated_by' => $this->admin->id]
        );
    }

    /**
     * Serial batteries received through PO → Goods Receipt (the ledger +3, one component asset per unit), then:
     *  1. one installed straight from the warehouse (DIRECT_ISSUE: the ledger −1 at installation);
     *  2. the same installation repeated (rejected — nothing moves twice);
     *  3. one issued through a Work Order Part Request (ledger −1 at issue), consumed, then installed on that
     *     Work Order (WO_ISSUE: the installation does not deduct again);
     *  4. the old battery recorded as removed on that Work Order and received back in the warehouse through the
     *     Removed Components flow (a zero-balance marker: the unit does not become available stock);
     *  the third unit stays in stock. Also an opening balance without a unit cost (stock pending valuation).
     */
    private function serializedInstallations(array $vehicles): void
    {
        $product = $this->product('Truck Battery 12V 100Ah');
        $warehouse = $this->warehouse();
        $service = app(PurchaseOrderService::class);
        $this->at($this->realNow->subDays(14));
        $po = $service->create($this->vendor('VND-SINAR'), $warehouse, ['expected_delivery_date' => $this->realNow->subDays(12)->toDateString(), 'notes' => self::MARKER.' serial batteries'], [
            ['product_id' => $product->id, 'quantity_ordered' => 3, 'unit_price' => 1800000],
        ], $this->admin->id);
        $service->transition($service->approve($service->transition($po, 'SUBMITTED'), $this->admin->id), 'ISSUED');
        $this->at($this->realNow->subDays(12));
        $po = PurchaseOrder::query()->with('items')->findOrFail($po->id);
        $receipt = app(GoodsReceiptService::class)->post($po, $warehouse, [['purchase_order_item_id' => $po->items->first()->id, 'quantity_accepted' => 3, 'quantity_rejected' => 0]], $this->admin->id, null, [
            'mode' => 'NEW', 'vendor_invoice_number' => 'DASH-INV-BAT', 'vendor_invoice_date' => $this->realNow->subDays(12)->toDateString(),
            'amount' => (string) $po->total, 'terms_of_payment_days' => 30, 'document' => $this->pdf('DASH-INV-BAT'),
        ]);
        $assets = ComponentAsset::query()->whereIn('goods_receipt_item_id', DB::table('goods_receipt_items')->where('goods_receipt_id', $receipt->id)->select('id'))->orderBy('asset_number')->get()->values();
        $components = app(ComponentAssetService::class);
        $vehicle = $vehicles['jkt']->fresh();

        $this->at($this->realNow->subDays(10));
        $components->install($assets[0], $vehicle, 'BATTERY_BOX_A', 53000, null, $this->admin->id);
        try {
            $components->install($assets[0]->fresh(), $vehicle, 'BATTERY_BOX_A', 53000, null, $this->admin->id); // a retry: rejected, nothing moves
        } catch (ComponentAssetException) {
        }

        $workOrders = app(WorkOrderService::class);
        $this->at($this->realNow->subDays(8));
        $wo = $workOrders->create($vehicle, ['workshop_id' => $this->workshop($vehicle)->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
            'complaint' => self::MARKER.' battery replacement'], $this->admin->id);
        $wo = $workOrders->assign($workOrders->approve($workOrders->submit($wo)));
        $this->assignMechanic($wo, $this->mechanics($vehicle)[0], 'PRIMARY');
        DemoWorkspaceAssignment::approve($wo, $this->admin->id, null, $this->realNow->subDays(8), 8);
        $this->at($this->realNow->subDays(8)->addHour());
        $wo = $workOrders->start($workOrders->schedule($wo->fresh()));
        $requests = app(WorkOrderPartRequestService::class);
        $this->at($this->realNow->subDays(8)->addHours(2));
        $request = $requests->request($wo->fresh(), [['product_id' => $product->id, 'quantity_requested' => 1]], null, $this->admin->id);
        $request = $requests->issue($requests->approve($request, null, $this->admin->id, null), $warehouse, $this->admin->id);
        foreach ($request->items as $item) {
            app(WorkOrderPartService::class)->consume(WorkOrderPlannedPart::query()->findOrFail($item->planned_part_id), null, $this->admin->id);
        }
        $components->install($assets[1]->fresh(), $vehicle, 'BATTERY_BOX_B', 53100, $wo->id, $this->admin->id);
        $removedComponents = app(WorkOrderRemovedComponentService::class);
        $removed = $removedComponents->remove($wo->fresh(), ['product_id' => $product->id, 'quantity' => 1, 'condition' => 'GOOD', 'notes' => 'Old battery taken off during the replacement.'], $this->admin->id);
        $removedComponents->returnToWarehouse($removed, $warehouse->id, 'Old battery received in the warehouse.', $this->admin->id);
        $this->at($this->realNow->subDays(8)->addHours(5));
        $workOrders->complete($workOrders->submitToQc($wo->fresh()));

        // Stock held without a recorded unit cost: pending valuation, not a value of 0 (a tool with no stock yet at Bandung).
        $bandung = $this->warehouse('ALPHA-BDG-WH1');
        $tool = Product::query()->where('tenant_id', $this->tenant->id)->where('product_type', 'TOOL')->orderBy('name')->first();
        if ($tool && ! WarehouseStock::query()->where('warehouse_id', $bandung->id)->where('product_id', $tool->id)->where('quantity_on_hand', '>', 0)->exists()) {
            $this->at($this->realNow->subDays(3));
            app(InventoryService::class)->receive($bandung, $tool, 4, 0, 'OPENING', null, null, $this->admin->id, 'Demo: opening balance without a unit cost.');
        }
    }

    /**
     * Two Work Orders that look like work done BEFORE work-time tracking existed: the interval rows are removed
     * after the fact, because that history genuinely cannot exist for them (it is never reconstructed or
     * estimated). One has no interval at all (unavailable), the other lost only its first interval after a rework
     * (partial: history starts part-way). Their mechanic cost / work time must show as unavailable or partial.
     */
    private function legacyHistoryWorkOrders(array $vehicles): void
    {
        $workOrders = app(WorkOrderService::class);
        foreach ([['none', $this->realNow->subDays(40), false], ['partial', $this->realNow->subDays(34), true]] as [$kind, $start, $partial]) {
            $vehicle = $vehicles['jkt']->fresh();
            $this->at($start);
            $wo = $workOrders->create($vehicle, ['workshop_id' => $this->workshop($vehicle)->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
                'complaint' => self::MARKER.' legacy work (before work-time tracking) — '.$kind], $this->admin->id);
            $wo = $workOrders->assign($workOrders->approve($workOrders->submit($wo)));
            $this->assignMechanic($wo, $this->mechanics($vehicle)[0], 'PRIMARY');
            DemoWorkspaceAssignment::approve($wo, $this->admin->id, null, $start, 8);
            $this->at($start->addHour());
            $wo = $workOrders->start($workOrders->schedule($wo->fresh()));
            $this->at($start->addHours(5));
            $workOrders->submitToQc($wo->fresh());
            if ($partial) {
                $this->at($start->addHours(6));
                $workOrders->rework($wo->fresh());
                $this->at($start->addHours(7));
                $workOrders->resume($wo->fresh());
                $this->at($start->addHours(9));
                $workOrders->submitToQc($wo->fresh());
            }
            $this->at($start->addHours(12));
            $workOrders->complete($wo->fresh());
            $intervals = DB::table('work_order_work_intervals')->where('work_order_id', $wo->id);
            $partial ? (clone $intervals)->where('cycle', 1)->delete() : $intervals->delete();
        }
    }

    /**
     * A serialized battery installed on the Jakarta truck 60 days ago, removed for reuse 20 days ago and
     * installed on the Bandung truck the same day: one open installation, so Installed Components
     * counts it once (on Bandung).
     */
    private function movedComponent(array $vehicles): void
    {
        $this->at($this->realNow->subDays(90));
        $asset = ComponentAsset::query()->where('tenant_id', $this->tenant->id)->where('serial_number', 'DASH-BAT-01')->first()
            ?? ComponentAsset::query()->create([
                'tenant_id' => $this->tenant->id, 'serial_number' => 'DASH-BAT-01', 'asset_number' => app(ComponentAssetRegisterService::class)->nextAssetNumber($this->tenant->id),
                'product_id' => $this->product('Truck Battery 12V 100Ah')->id, 'component_group_id' => ComponentGroup::query()->whereNull('tenant_id')->where('code', 'CG-ELEC')->value('id'),
                'purchase_date' => $this->realNow->subDays(90)->toDateString(), 'purchase_cost' => 1850000, 'current_status' => 'IN_STOCK', 'current_warehouse_id' => $this->warehouse()->id,
            ]);
        if ($asset->current_status !== 'IN_STOCK') {
            return;
        }
        if ($asset->wasRecentlyCreated) {
            // Received into the warehouse ledger like any stock, so installing it takes it out exactly once.
            app(InventoryService::class)->receive($this->warehouse(), $this->product('Truck Battery 12V 100Ah'), 1, 1850000, 'RECEIPT', null, null, $this->admin->id, 'Demo: serial battery DASH-BAT-01 received.');
        }
        $components = app(ComponentAssetService::class);
        $this->at($this->realNow->subDays(60));
        $components->install($asset, $vehicles['jkt']->fresh(), 'ENGINE_BAY', 52000, null, $this->admin->id);
        $this->at($this->realNow->subDays(20));
        $components->remove($asset->fresh(), 'Moved to the Bandung unit', 'REUSE', 58000, 'GOOD', null, null, $this->admin->id, $this->warehouse()->id);
        $this->at($this->realNow->subDays(20)->addHours(3));
        $components->install($asset->fresh(), $vehicles['bdg']->fresh(), 'ENGINE_BAY', 51000, null, $this->admin->id);
    }
}
