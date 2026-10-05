<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\History\Services\HistoryService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Maintenance History (global): the timeline of every vehicle the user may see — the whole tenant
 * for a tenant-wide data scope, only the assigned branches otherwise. No vehicle has to be picked;
 * branch / vehicle filters are optional and must stay inside the user's scope.
 */
class MaintenanceHistoryController extends Controller
{
    public function __construct(
        private readonly HistoryService $history,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'type' => ['nullable', 'in:'.implode(',', HistoryService::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $allowed = $this->scope->allowedBranchIds($user, $tenantId);

        if (! empty($validated['branch_id'])) {
            abort_unless($this->scope->canAccessBranch($user, $tenantId, $validated['branch_id']), 403, 'This branch is outside your assigned data scope.');
        }
        if (! empty($validated['vehicle_id'])) {
            $vehicle = Vehicle::query()->where('tenant_id', $tenantId)->find($validated['vehicle_id']);
            abort_unless($vehicle !== null, 404);
            abort_unless($this->scope->canAccessBranch($user, $tenantId, $vehicle->branch_id), 403, 'This vehicle is outside your assigned data scope.');
        }

        return $this->paginated($this->history->paginate($tenantId, $validated, $allowed, (int) ($validated['per_page'] ?? 25)));
    }
}
