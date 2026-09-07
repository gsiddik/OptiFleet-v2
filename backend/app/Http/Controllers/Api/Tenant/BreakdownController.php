<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Breakdown\Services\BreakdownService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreBreakdownRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class BreakdownController extends Controller
{
    public function __construct(
        private readonly BreakdownService $breakdowns,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Breakdown::query()->with(['vehicle', 'branch']);
        $this->scope->applyBranchScope($query, $user, $tenantId, 'branch_id');

        foreach (['status', 'severity', 'vehicle_id', 'branch_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->latest('reported_at')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreBreakdownRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $vehicle->branch_id), 403);

        $breakdown = $this->breakdowns->report($vehicle, $request->only(['location', 'severity', 'description', 'evidence']), $this->context->user()->id);

        return $this->ok($breakdown, 201);
    }

    public function show(Breakdown $breakdown)
    {
        $this->authorizeScope($breakdown);

        return $this->ok($breakdown->load(['vehicle', 'branch']));
    }

    public function verify(Breakdown $breakdown)
    {
        return $this->transition($breakdown, 'VERIFIED');
    }

    public function assess(Request $request, Breakdown $breakdown)
    {
        return $this->transition($breakdown, 'ASSESSED', $request->input('note'));
    }

    public function requireRepair(Request $request, Breakdown $breakdown)
    {
        return $this->transition($breakdown, 'REPAIR_REQUIRED', $request->input('note'));
    }

    public function resolve(Request $request, Breakdown $breakdown)
    {
        return $this->transition($breakdown, 'RESOLVED', $request->input('note'));
    }

    public function convertToMaintenanceRequest(Request $request, Breakdown $breakdown)
    {
        $this->authorizeScope($breakdown);

        $maintenanceRequest = $this->breakdowns->convertToMaintenanceRequest(
            $breakdown,
            $request->only(['complaint', 'priority']),
            $this->context->user()->id
        );

        return $this->ok($maintenanceRequest, 201);
    }

    private function transition(Breakdown $breakdown, string $to, ?string $note = null)
    {
        $this->authorizeScope($breakdown);

        return $this->ok($this->breakdowns->transition($breakdown, $to, $note));
    }

    private function authorizeScope(Breakdown $breakdown): void
    {
        abort_unless($breakdown->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $breakdown->branch_id),
            403,
            'This breakdown is outside your assigned data scope.'
        );
    }
}
