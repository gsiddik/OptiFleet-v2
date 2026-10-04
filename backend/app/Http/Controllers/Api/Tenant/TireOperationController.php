<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Tire\Models\TireOperation;
use App\Domain\Tire\Services\TireOperationService;
use App\Domain\Tire\Support\TireOperationStatus;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tire Operations (Replacement / Rotation / Inspection planned on a vehicle, executed through the
 * Work Order created with it). Every endpoint is tenant- and branch-scoped; creating or editing an
 * operation needs the permission of its type (tire.install / tire.rotate / tire.inspect) plus the
 * Work Order permission it implies.
 */
class TireOperationController extends Controller
{
    private const TYPE_PERMISSION = [
        TireOperation::REPLACEMENT => 'tire.install',
        TireOperation::ROTATION => 'tire.rotate',
        TireOperation::INSPECTION => 'tire.inspect',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly DataScopeService $scope,
        private readonly PermissionService $permissions,
        private readonly TireOperationService $operations,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'operation_type' => ['nullable', Rule::in(TireOperation::TYPES)],
            'status' => ['nullable', Rule::in(TireOperationStatus::ALL)],
            'vehicle_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->paginated($this->operations->list($this->context->tenantId(), $this->context->user(), $validated, (int) ($validated['per_page'] ?? 20)));
    }

    public function show(TireOperation $tireOperation)
    {
        $this->authorizeOperation($tireOperation);

        return $this->ok($this->operations->present($tireOperation));
    }

    /** Work Order → Tire Operations tab. */
    public function forWorkOrder(WorkOrder $workOrder)
    {
        abort_unless($workOrder->tenant_id === $this->context->tenantId(), 404);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $workOrder->branch_id), 403);
        $operation = TireOperation::query()->where('work_order_id', $workOrder->id)->first();

        return $this->ok($operation ? $this->operations->present($operation) : null);
    }

    public function context(Request $request, Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);

        return $this->ok($this->operations->context($vehicle, $request->string('operation_id')->value() ?: null));
    }

    public function replacementCandidates(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'operation_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok($this->operations->replacementCandidates($this->context->tenantId(), $validated['product_id'], $validated['operation_id'] ?? null, $validated['search'] ?? null));
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request, true);
        $vehicle = Vehicle::query()->find($validated['vehicle_id']);
        abort_unless($vehicle, 422, 'Select a vehicle.');
        $this->authorizeVehicle($vehicle);
        $this->authorizeType($validated['operation_type']);
        $workshopId = $validated['workshop_id'] ?? $vehicle->default_workshop_id;
        if ($workshopId) {
            abort_unless($this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workshopId), 403, 'This workshop is outside your assigned data scope.');
        }

        $operation = $this->operations->create($vehicle, $validated, $this->context->user()->id);

        return $this->ok($this->operations->present($operation), 201);
    }

    public function update(Request $request, TireOperation $tireOperation)
    {
        $this->authorizeOperation($tireOperation);
        $validated = $this->validated($request, false);
        $this->authorizeType($validated['operation_type']);
        $this->authorizeType($tireOperation->operation_type);

        $operation = $this->operations->update($tireOperation, $validated, $this->context->user()->id);

        return $this->ok($this->operations->present($operation));
    }

    public function cancel(Request $request, TireOperation $tireOperation)
    {
        $this->authorizeOperation($tireOperation);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $operation = $this->operations->cancel($tireOperation, $validated['reason'] ?? null, $this->context->user()->id);

        return $this->ok($this->operations->present($operation));
    }

    private function validated(Request $request, bool $creating): array
    {
        $request->merge(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $request->only(['operated_time', 'odometer'])));

        return $request->validate([
            'vehicle_id' => [$creating ? 'required' : 'nullable', 'uuid'],
            'workshop_id' => ['nullable', 'uuid', Rule::exists('workshops', 'id')->where('tenant_id', $this->context->tenantId())],
            'operation_type' => ['required', Rule::in(TireOperation::TYPES)],
            'operated_date' => ['required', 'date_format:Y-m-d'],
            'operated_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            // Physical odometer reading: decimal text, up to 2 decimals, never negative.
            'odometer' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'items' => ['nullable', 'array', 'max:64'],
            'items.*.position_code' => ['required', 'string', 'max:20'],
            'items.*.replacement_tire_id' => ['nullable', 'uuid'],
            'items.*.tread_depth_mm' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'rotation_pairs' => ['nullable', 'array', 'max:32'],
            'rotation_pairs.*.from' => ['required', 'string', 'max:20'],
            'rotation_pairs.*.to' => ['required', 'string', 'max:20'],
        ], [
            'operated_time.regex' => 'Use the 24-hour format HH:mm, for example 07:30 or 14:05.',
            'odometer.required' => 'Enter the KM at Tire Operations (physical odometer reading).',
            'odometer.regex' => 'Enter a number of kilometres (0 or more, up to 2 decimals), for example 12500.75.',
            'items.*.tread_depth_mm.regex' => 'Enter a tread depth in mm (0 or more, up to 2 decimals), for example 8.5.',
        ]);
    }

    private function authorizeType(string $type): void
    {
        $permission = self::TYPE_PERMISSION[$type];
        abort_unless($this->permissions->userHasPermission($this->context->user(), $permission, $this->context->tenantId()), 403, "This action requires the {$permission} permission.");
    }

    private function authorizeOperation(TireOperation $operation): void
    {
        abort_unless($operation->tenant_id === $this->context->tenantId(), 404);
        $vehicle = Vehicle::query()->withoutGlobalScopes()->find($operation->vehicle_id);
        abort_unless($vehicle && $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicle->branch_id), 403, 'This vehicle is outside your assigned data scope.');
    }

    private function authorizeVehicle(Vehicle $vehicle): void
    {
        abort_unless($vehicle->tenant_id === $this->context->tenantId(), 404);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicle->branch_id), 403, 'This vehicle is outside your assigned data scope.');
    }
}
