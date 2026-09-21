<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Workshop\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Section 33: read-only aggregation feeding the day/week scheduler view —
 * Workspace x reservation x Work Order x Vehicle x assigned mechanics for
 * a time window. Conflict prevention itself lives in
 * WorkspaceReservationService; this endpoint only renders what already
 * exists.
 */
class WorkshopSchedulerController extends Controller
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'workshop_id' => ['required', 'uuid', 'exists:workshops,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $tenantId = $this->context->tenantId();
        abort_unless($this->scope->canAccessWorkshop($this->context->user(), $tenantId, $request->input('workshop_id')), 403);

        $workspaces = Workspace::query()
            ->where('tenant_id', $tenantId)
            ->where('workshop_id', $request->input('workshop_id'))
            ->with(['reservations' => function ($q) use ($request) {
                $q->where('end_at', '>=', $request->input('from'))
                    ->where('start_at', '<=', $request->input('to'))
                    ->whereIn('status', ['RESERVED', 'ACTIVE'])
                    // "Next Improvement Tenant Portal - Products" (Scheduler): a
                    // Closed Work Order's card is removed from the scheduler
                    // entirely, even if its reservation row itself hasn't been
                    // marked COMPLETED/CANCELLED.
                    ->whereHas('workOrder', fn ($wq) => $wq->where('status', '!=', 'CLOSED'))
                    ->with(['workOrder.vehicle', 'workOrder.mechanicAssignments.worker'])
                    ->orderBy('start_at');
            }])
            ->get();

        return $this->ok($workspaces);
    }
}
