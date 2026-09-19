<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Models\WorkspaceReservation;
use App\Domain\Workshop\Services\WorkspaceReservationService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkspaceReservationController extends Controller
{
    public function __construct(
        private readonly WorkspaceReservationService $reservations,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = WorkspaceReservation::query()->with(['workspace.workshop', 'workOrder.vehicle']);
        $allowedWorkshopIds = $this->scope->allowedWorkshopIds($user, $tenantId);
        if ($allowedWorkshopIds !== null) {
            $query->whereHas('workspace', fn ($q) => $q->whereIn('workshop_id', $allowedWorkshopIds));
        }

        if ($workspaceId = $request->string('workspace_id')->value()) {
            $query->where('workspace_id', $workspaceId);
        }
        if ($workOrderId = $request->string('work_order_id')->value()) {
            $query->where('work_order_id', $workOrderId);
        }
        if ($workshopId = $request->string('workshop_id')->value()) {
            $query->whereHas('workspace', fn ($q) => $q->where('workshop_id', $workshopId));
        }
        if ($from = $request->string('from')->value()) {
            $query->where('end_at', '>=', $from);
        }
        if ($to = $request->string('to')->value()) {
            $query->where('start_at', '<=', $to);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('start_at')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $request->validate([
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ]);

        $workspace = Workspace::query()->findOrFail($request->input('workspace_id'));
        abort_unless($workspace->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWorkshop($this->context->user(), $tenantId, $workspace->workshop_id), 403);

        $reservation = $this->reservations->reserve(
            $workspace,
            new \DateTimeImmutable($request->input('start_at')),
            new \DateTimeImmutable($request->input('end_at')),
            $request->input('work_order_id'),
            $this->context->user()->id,
        );

        return $this->ok($reservation, 201);
    }

    public function activate(WorkspaceReservation $reservation)
    {
        $this->authorizeScope($reservation);

        return $this->ok($this->reservations->activate($reservation));
    }

    public function complete(WorkspaceReservation $reservation)
    {
        $this->authorizeScope($reservation);

        return $this->ok($this->reservations->complete($reservation));
    }

    public function cancel(WorkspaceReservation $reservation)
    {
        $this->authorizeScope($reservation);

        return $this->ok($this->reservations->cancel($reservation));
    }

    private function authorizeScope(WorkspaceReservation $reservation): void
    {
        abort_unless($reservation->tenant_id === $this->context->tenantId(), 404);
        $workshopId = $reservation->workspace?->workshop_id ?? Workspace::query()->find($reservation->workspace_id)?->workshop_id;
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workshopId),
            403,
            'This reservation is outside your assigned data scope.'
        );
    }
}
