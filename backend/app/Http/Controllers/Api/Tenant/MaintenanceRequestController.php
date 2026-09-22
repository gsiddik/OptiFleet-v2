<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreMaintenanceRequestRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class MaintenanceRequestController extends Controller
{
    public function __construct(
        private readonly MaintenanceRequestService $requests,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = MaintenanceRequest::query()->with(['vehicle', 'branch', 'workshop', 'requestedByUser']);
        $this->scope->applyBranchScope($query, $user, $tenantId, 'branch_id');

        foreach (['status', 'priority', 'source_type', 'vehicle_id', 'branch_id', 'workshop_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreMaintenanceRequestRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $vehicle->branch_id), 403);

        $maintenanceRequest = $this->requests->create($vehicle, array_merge($request->validated(), [
            'source_type' => 'USER',
            'status' => 'DRAFT',
        ]), $this->context->user()->id);

        return $this->ok($maintenanceRequest, 201);
    }

    public function show(MaintenanceRequest $maintenanceRequest)
    {
        $this->authorizeScope($maintenanceRequest);

        return $this->ok($maintenanceRequest->load(['vehicle', 'branch', 'workshop', 'componentGroup', 'requestedByUser']));
    }

    public function submit(MaintenanceRequest $maintenanceRequest)
    {
        return $this->transition($maintenanceRequest, 'SUBMITTED');
    }

    public function review(MaintenanceRequest $maintenanceRequest)
    {
        return $this->transition($maintenanceRequest, 'UNDER_REVIEW');
    }

    public function approve(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        return $this->transition($maintenanceRequest, 'APPROVED', $request->input('note'));
    }

    public function reject(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        $request->validate(['note' => ['required', 'string']]);

        return $this->transition($maintenanceRequest, 'REJECTED', $request->input('note'));
    }

    public function cancel(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        $request->validate(['note' => ['required', 'string']]);

        return $this->transition($maintenanceRequest, 'CANCELLED', $request->input('note'));
    }

    private function transition(MaintenanceRequest $maintenanceRequest, string $to, ?string $note = null)
    {
        $this->authorizeScope($maintenanceRequest);

        return $this->ok($this->requests->transition($maintenanceRequest, $to, $this->context->user()->id, $note));
    }

    private function authorizeScope(MaintenanceRequest $maintenanceRequest): void
    {
        abort_unless($maintenanceRequest->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $maintenanceRequest->branch_id),
            403,
            'This maintenance request is outside your assigned data scope.'
        );
    }
}
