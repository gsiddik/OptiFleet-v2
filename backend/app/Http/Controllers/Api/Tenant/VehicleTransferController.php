<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleTransfer;
use App\Domain\Vehicle\Services\VehicleTransferService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleTransferRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class VehicleTransferController extends Controller
{
    public function __construct(
        private readonly VehicleTransferService $transfers,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = VehicleTransfer::query()->with(['vehicle', 'fromBranch', 'toBranch']);
        $this->scope->applyBranchScope($query, $this->context->user(), $tenantId, 'from_branch_id');

        if ($vehicleId = $request->string('vehicle_id')->value()) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreVehicleTransferRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $vehicle->branch_id), 403);

        $transfer = $this->transfers->createDraft($vehicle, $request->only(['to_branch_id', 'to_workshop_id', 'reason']), $this->context->user()->id);

        return $this->ok($transfer, 201);
    }

    public function show(VehicleTransfer $vehicleTransfer)
    {
        $this->authorizeScope($vehicleTransfer);

        return $this->ok($vehicleTransfer->load(['vehicle', 'fromBranch', 'toBranch']));
    }

    public function submit(VehicleTransfer $vehicleTransfer)
    {
        return $this->transition($vehicleTransfer, 'REQUESTED');
    }

    public function approve(Request $request, VehicleTransfer $vehicleTransfer)
    {
        return $this->transition($vehicleTransfer, 'APPROVED', $request->input('note'));
    }

    public function dispatch(VehicleTransfer $vehicleTransfer)
    {
        return $this->transition($vehicleTransfer, 'IN_TRANSIT');
    }

    public function receive(VehicleTransfer $vehicleTransfer)
    {
        return $this->transition($vehicleTransfer, 'RECEIVED');
    }

    public function complete(VehicleTransfer $vehicleTransfer)
    {
        return $this->transition($vehicleTransfer, 'COMPLETED');
    }

    public function reject(Request $request, VehicleTransfer $vehicleTransfer)
    {
        return $this->transition($vehicleTransfer, 'REJECTED', $request->input('note'));
    }

    public function cancel(Request $request, VehicleTransfer $vehicleTransfer)
    {
        return $this->transition($vehicleTransfer, 'CANCELLED', $request->input('note'));
    }

    private function transition(VehicleTransfer $vehicleTransfer, string $to, ?string $note = null)
    {
        $this->authorizeScope($vehicleTransfer);

        $updated = $this->transfers->transition($vehicleTransfer, $to, $this->context->user()->id, $note);

        return $this->ok($updated->load(['vehicle', 'fromBranch', 'toBranch']));
    }

    private function authorizeScope(VehicleTransfer $vehicleTransfer): void
    {
        abort_unless($vehicleTransfer->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicleTransfer->from_branch_id),
            403,
            'This transfer is outside your assigned data scope.'
        );
    }
}
