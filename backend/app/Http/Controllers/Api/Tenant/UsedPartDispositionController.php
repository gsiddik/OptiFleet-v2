<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Services\UsedPartDispositionService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * G-15: Used Sparepart Processing queue — every USED_GOOD/USED_FAULTY
 * return from Phase A surfaces here for inspect -> propose -> decide.
 */
class UsedPartDispositionController extends Controller
{
    public function __construct(
        private readonly UsedPartDispositionService $dispositions,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = WorkOrderPartReturn::query()
            ->whereIn('condition', ['USED_GOOD', 'USED_FAULTY'])
            ->with(['product', 'warehouse', 'plannedPart.workOrder']);

        if ($status = $request->string('disposition_status')->value()) {
            $query->where('disposition_status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);

        return $this->ok($usedPartReturn->load(['product', 'warehouse', 'plannedPart.workOrder']));
    }

    public function inspect(Request $request, WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);
        $validated = $request->validate([
            'accepted_quantity' => ['required', 'numeric', 'gt:0'],
            'condition' => ['required', 'string', 'in:USED_GOOD,USED_FAULTY'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->dispositions->inspect(
            $usedPartReturn, (float) $validated['accepted_quantity'], $validated['condition'],
            $validated['notes'] ?? null, $this->context->user()->id,
        ));
    }

    public function proposeDisposition(Request $request, WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);
        $validated = $request->validate([
            'disposition' => ['required', 'string', 'in:'.implode(',', WorkOrderPartReturn::DISPOSITIONS)],
            'reason' => ['nullable', 'string'],
        ]);

        return $this->ok($this->dispositions->proposeDisposition(
            $usedPartReturn, $validated['disposition'], $validated['reason'] ?? null, $this->context->user()->id,
        ));
    }

    public function decide(Request $request, WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'note' => ['nullable', 'string'],
        ]);

        return $this->ok($this->dispositions->decide(
            $usedPartReturn, $validated['decision'], $this->context->user()->id, $validated['note'] ?? null,
        ));
    }

    private function authorizeScope(WorkOrderPartReturn $return): void
    {
        abort_unless($return->tenant_id === $this->context->tenantId(), 404);
    }
}
