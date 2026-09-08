<?php

namespace App\Http\Controllers\Api\Platform\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Monitoring\DriftAssessmentService;
use App\Domain\Intelligence\Monitoring\ModelMonitoringService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Phase 7 Section 48-50, 58 — model monitoring + drift, platform-scope. */
class IntelligenceMonitoringController extends Controller
{
    public function __construct(
        private readonly ModelMonitoringService $monitoring,
        private readonly DriftAssessmentService $drift,
    ) {}

    public function models(Request $request)
    {
        $query = IntelligenceModel::query()->withoutGlobalScopes()->where('status', IntelligenceModel::STATUS_ACTIVE);
        if ($modelId = $request->string('model_id')->value()) {
            $query = IntelligenceModel::query()->withoutGlobalScopes()->where('id', $modelId);
        }

        return $this->ok($query->get()->map(fn (IntelligenceModel $m) => $this->monitoring->forModel($m))->values());
    }

    public function drift(Request $request)
    {
        $request->validate(['tenant_id' => 'required|uuid', 'window_days' => 'nullable|integer|min:1|max:365']);

        return $this->ok($this->drift->assess($request->string('tenant_id')->value(), $request->integer('window_days') ?: null));
    }
}
