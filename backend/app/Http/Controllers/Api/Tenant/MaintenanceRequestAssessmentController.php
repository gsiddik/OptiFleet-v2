<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestAssessmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SaveMaintenanceRequestAssessmentRequest;
use App\Support\TenantContext;

class MaintenanceRequestAssessmentController extends Controller
{
    public function __construct(
        private readonly MaintenanceRequestAssessmentService $assessments,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function show(MaintenanceRequest $maintenanceRequest)
    {
        $this->authorizeScope($maintenanceRequest);

        return $this->ok($maintenanceRequest->assessment()->with('groups')->first());
    }

    public function store(SaveMaintenanceRequestAssessmentRequest $request, MaintenanceRequest $maintenanceRequest)
    {
        $this->authorizeScope($maintenanceRequest);

        $assessment = $this->assessments->save(
            $maintenanceRequest,
            $request->input('groups'),
            $request->input('notes'),
            $this->context->user()->id
        );

        return $this->ok($assessment);
    }

    public function destroy(MaintenanceRequest $maintenanceRequest)
    {
        $this->authorizeScope($maintenanceRequest);
        $this->assessments->clear($maintenanceRequest);

        return $this->ok(['cleared' => true]);
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
