<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\DocumentGeneration\Support\DocumentSource;
use App\Domain\Partner\Models\Partner;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalService;
use App\Domain\WorkOrder\Services\WorkOrderExternalServiceService;
use App\Http\Controllers\Concerns\PrintsDocuments;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkOrderExternalServiceController extends Controller
{
    use PrintsDocuments;

    public function __construct(
        private readonly WorkOrderExternalServiceService $externalServices,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function store(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'description' => ['required', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'requested_parts_services' => ['nullable', 'string'],
            'photo_evidence' => ['nullable', 'string', 'max:255'],
            'condition_notes' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:LOW,MEDIUM,HIGH,URGENT'],
            'reference_number' => ['nullable', 'string'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $partner = Partner::query()->findOrFail($validated['partner_id']);
        abort_unless($partner->tenant_id === $this->context->tenantId(), 404);

        return $this->ok($this->externalServices->create(
            $workOrder, $partner, collect($validated)->except('partner_id')->all(), $this->context->user()->id,
        ), 201);
    }

    public function complete(WorkOrder $workOrder, WorkOrderExternalService $externalService)
    {
        $this->authorizeScope($workOrder);
        abort_unless($externalService->work_order_id === $workOrder->id, 404);

        return $this->ok($this->externalServices->complete($externalService, $this->context->user()->id));
    }

    public function cancel(WorkOrder $workOrder, WorkOrderExternalService $externalService)
    {
        $this->authorizeScope($workOrder);
        abort_unless($externalService->work_order_id === $workOrder->id, 404);

        return $this->ok($this->externalServices->cancel($externalService, $this->context->user()->id));
    }

    /**
     * Final reconciliation (queued ADJUST): VMS's Maintenance Memo "Save
     * and Print" — mirrors WorkOrderController::print()'s effective-
     * template render + PDF pattern exactly, for the 'maintenance_memo'
     * document type.
     */
    public function print(Request $request, WorkOrder $workOrder, WorkOrderExternalService $externalService)
    {
        $this->authorizeScope($workOrder);
        abort_unless($externalService->work_order_id === $workOrder->id, 404);

        return $this->printDocument($request, $this->maintenanceMemoDocument($workOrder, $externalService));
    }

    public function printGenerations(WorkOrder $workOrder, WorkOrderExternalService $externalService)
    {
        $this->authorizeScope($workOrder);
        abort_unless($externalService->work_order_id === $workOrder->id, 404);

        return $this->documentGenerations($this->maintenanceMemoDocument($workOrder, $externalService));
    }

    public function generatePrint(Request $request, WorkOrder $workOrder, WorkOrderExternalService $externalService)
    {
        $this->authorizeScope($workOrder);
        abort_unless($externalService->work_order_id === $workOrder->id, 404);

        return $this->generateDocument($request, $this->maintenanceMemoDocument($workOrder, $externalService));
    }

    private function maintenanceMemoDocument(WorkOrder $workOrder, WorkOrderExternalService $externalService): DocumentSource
    {
        return new DocumentSource(
            'maintenance_memo', 'work_order_external_service', $externalService->id, $workOrder->tenant_id, 'memo-'.$externalService->id.'.pdf',
            fn (string $locale) => DocumentTemplateContextBuilder::forMaintenanceMemo($externalService, $locale),
            branchId: $workOrder->branch_id, workshopId: $workOrder->workshop_id,
        );
    }

    private function authorizeScope(WorkOrder $workOrder): void
    {
        abort_unless($workOrder->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workOrder->workshop_id),
            403,
            'This Work Order is outside your assigned data scope.'
        );
    }
}
