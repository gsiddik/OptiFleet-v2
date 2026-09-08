<?php

namespace App\Http\Controllers\Api\Tenant\Intelligence;

use App\Domain\Intelligence\Support\EntityScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Phase 7 Section 36/42/57 — raw prediction/insight history with filters, respecting data scope. */
class PredictionHistoryController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntityScopeResolver $scope,
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'entity_id' => 'nullable|uuid',
            'prediction_type' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $tenantId = $this->context->tenantId();
        $entityId = $request->string('entity_id')->value() ?: null;
        if ($entityId) {
            abort_unless($this->scope->canAccessVehicle($this->context->user(), $tenantId, $entityId), 403, 'This entity is outside your assigned data scope.');
        }

        $query = DB::connection('mongodb')->table('intelligence_predictions')->where('tenant_id', $tenantId);

        if (! $entityId) {
            $allowed = $this->scope->allowedVehicleIds($this->context->user(), $tenantId);
            if ($allowed !== null) {
                $query->whereIn('entity_id', $allowed);
            }
        } else {
            $query->where('entity_id', $entityId);
        }

        $query->when($request->filled('prediction_type'), fn ($q) => $q->where('prediction_type', $request->string('prediction_type')->value()))
            ->when($request->filled('from'), fn ($q) => $q->where('business_date', '>=', $request->string('from')->value()))
            ->when($request->filled('to'), fn ($q) => $q->where('business_date', '<=', $request->string('to')->value()));

        $predictions = $query->orderByDesc('business_date')->limit(500)->get();

        return $this->ok(['predictions' => $predictions]);
    }
}
