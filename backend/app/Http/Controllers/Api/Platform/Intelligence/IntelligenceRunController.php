<?php

namespace App\Http\Controllers\Api\Platform\Intelligence;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Intelligence\Services\PredictionRunService;
use App\Http\Controllers\Controller;
use App\Jobs\Intelligence\EvaluateOutcomesJob;
use Illuminate\Http\Request;

/**
 * On-demand (always async, always audited) prediction runs and outcome evaluation for one
 * tenant — the same work the scheduled `intelligence:predict` / `intelligence:evaluate-outcomes`
 * commands do. Only tenants entitled to MAINTENANCE_INTELLIGENCE are eligible.
 */
class IntelligenceRunController extends Controller
{
    public function __construct(
        private readonly PredictionRunService $runs,
        private readonly AuditService $audit,
    ) {}

    public function predict(Request $request)
    {
        $validated = $this->validateRun($request, ['date' => 'nullable|date_format:Y-m-d']);
        if ($response = $this->ineligible($validated['tenant_id'])) {
            return $response;
        }

        $this->audit->log('IntelligencePredictionRun', 'manual-'.now()->timestamp, 'requested', null, $validated, $validated['tenant_id']);
        $this->runs->run($validated['date'] ?? null, $validated['tenant_id'], $validated['target'] ?? null);

        return $this->message('Prediction run dispatched.', 202);
    }

    public function evaluate(Request $request)
    {
        $validated = $this->validateRun($request);
        if ($response = $this->ineligible($validated['tenant_id'])) {
            return $response;
        }

        $this->audit->log('IntelligenceModelEvaluation', 'manual-'.now()->timestamp, 'requested', null, $validated, $validated['tenant_id']);
        $targets = isset($validated['target']) ? [$validated['target']] : array_keys(config('intelligence.model_targets', []));
        foreach ($targets as $target) {
            EvaluateOutcomesJob::dispatch($validated['tenant_id'], $target);
        }

        return $this->message('Outcome evaluation dispatched.', 202);
    }

    private function validateRun(Request $request, array $extra = []): array
    {
        return $request->validate([
            'tenant_id' => 'required|uuid|exists:tenants,id',
            'target' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('intelligence.model_targets', [])))],
        ] + $extra);
    }

    private function ineligible(string $tenantId)
    {
        return $this->runs->eligibleTenants($tenantId)->isEmpty()
            ? $this->message('This tenant is not active or not entitled to Maintenance Intelligence.', 422)
            : null;
    }
}
