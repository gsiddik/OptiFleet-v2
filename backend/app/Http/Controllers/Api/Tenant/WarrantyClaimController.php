<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\Warranty\Services\WarrantyClaimService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WarrantyClaimController extends Controller
{
    public function __construct(
        private readonly WarrantyClaimService $claims,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = WarrantyClaim::query()->where('tenant_id', $tenantId)->with(['partner', 'vehicle', 'product', 'componentAsset', 'tire']);
        $allowedBranchIds = $this->scope->allowedBranchIds($this->context->user(), $tenantId);
        if ($allowedBranchIds !== null) {
            $query->whereHas('vehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($vehicleId = $request->string('vehicle_id')->value()) {
            $query->where('vehicle_id', $vehicleId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid', 'exists:vehicles,id'],
            'warranty_id' => ['nullable', 'uuid', 'exists:warranties,id'],
            'partner_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'component_asset_id' => ['nullable', 'uuid', 'exists:component_assets,id'],
            'tire_id' => ['nullable', 'uuid', 'exists:tires,id'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
            'failure_date' => ['required', 'date'],
            'failure_odometer' => ['nullable', 'numeric', 'min:0'],
            'claim_amount' => ['nullable', 'numeric', 'min:0'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($validated['vehicle_id']);
        abort_unless($vehicle->tenant_id === $tenantId, 404);

        $claim = $this->claims->create($vehicle, collect($validated)->except('vehicle_id')->all(), $this->context->user()->id);

        return $this->ok($claim, 201);
    }

    public function show(WarrantyClaim $warrantyClaim)
    {
        $this->authorizeScope($warrantyClaim);

        return $this->ok($warrantyClaim->load(['partner', 'vehicle', 'product', 'componentAsset', 'tire', 'workOrder']));
    }

    public function submit(WarrantyClaim $warrantyClaim)
    {
        return $this->transition($warrantyClaim, 'SUBMITTED');
    }

    public function review(WarrantyClaim $warrantyClaim)
    {
        return $this->transition($warrantyClaim, 'UNDER_REVIEW');
    }

    public function approve(Request $request, WarrantyClaim $warrantyClaim)
    {
        return $this->transition($warrantyClaim, 'APPROVED', $request->input('note'));
    }

    public function reject(Request $request, WarrantyClaim $warrantyClaim)
    {
        $request->validate(['note' => ['required', 'string']]);

        return $this->transition($warrantyClaim, 'REJECTED', $request->input('note'));
    }

    public function markReplacement(WarrantyClaim $warrantyClaim)
    {
        return $this->transition($warrantyClaim, 'REPLACEMENT');
    }

    public function markRepair(WarrantyClaim $warrantyClaim)
    {
        return $this->transition($warrantyClaim, 'REPAIR');
    }

    public function settle(WarrantyClaim $warrantyClaim)
    {
        return $this->transition($warrantyClaim, 'SETTLED');
    }

    public function close(WarrantyClaim $warrantyClaim)
    {
        return $this->transition($warrantyClaim, 'CLOSED');
    }

    private function transition(WarrantyClaim $warrantyClaim, string $to, ?string $note = null)
    {
        $this->authorizeScope($warrantyClaim);

        return $this->ok($this->claims->transition($warrantyClaim, $to, $this->context->user()->id, $note));
    }

    private function authorizeScope(WarrantyClaim $claim): void
    {
        abort_unless($claim->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), \App\Domain\Vehicle\Models\Vehicle::query()->find($claim->vehicle_id)?->branch_id ?? ''),
            403,
            'This warranty claim is outside your assigned data scope.'
        );
    }
}
