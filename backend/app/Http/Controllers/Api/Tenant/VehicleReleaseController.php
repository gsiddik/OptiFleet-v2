<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\VehicleRelease\Services\VehicleReleaseService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class VehicleReleaseController extends Controller
{
    public function __construct(
        private readonly VehicleReleaseService $releases,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function store(Request $request, WorkOrder $workOrder)
    {
        abort_unless($workOrder->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workOrder->workshop_id),
            403,
            'This Work Order is outside your assigned data scope.'
        );

        $validated = $request->validate([
            'release_odometer' => ['nullable', 'numeric', 'min:0'],
            'release_condition' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $release = $this->releases->release($workOrder, $validated, $this->context->user()->id);

        return $this->ok($release, 201);
    }
}
