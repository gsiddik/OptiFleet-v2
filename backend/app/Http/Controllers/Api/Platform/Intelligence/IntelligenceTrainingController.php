<?php

namespace App\Http\Controllers\Api\Platform\Intelligence;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Http\Controllers\Controller;
use App\Jobs\Intelligence\TrainModelJob;
use Illuminate\Http\Request;

/** Phase 7 Section 42, 50, 63 — controlled (always async, always audited) training trigger. */
class IntelligenceTrainingController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function train(Request $request)
    {
        $request->validate([
            'tenant_id' => 'required|uuid',
            'target' => 'required|string|max:100',
            'scope' => 'nullable|in:'.IntelligenceModel::SCOPE_TENANT.','.IntelligenceModel::SCOPE_GLOBAL,
        ]);

        if (! array_key_exists($request->string('target')->value(), config('intelligence.model_targets', []))) {
            return $this->message('Unknown model target.', 422);
        }

        $scope = $request->string('scope')->value() ?: IntelligenceModel::SCOPE_TENANT;

        $this->audit->log(
            resourceType: 'IntelligenceTraining', resourceId: 'manual-'.now()->timestamp, action: 'requested',
            oldValues: null,
            newValues: $request->only(['tenant_id', 'target', 'scope']),
            tenantId: $request->string('tenant_id')->value(),
        );

        TrainModelJob::dispatch($request->string('tenant_id')->value(), $request->string('target')->value(), $scope, 'platform_manual');

        return $this->message('Training dispatched.', 202);
    }
}
