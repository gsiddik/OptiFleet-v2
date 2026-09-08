<?php

namespace App\Http\Controllers\Api\Tenant\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceRecommendation;
use App\Domain\Intelligence\Services\RecommendationReviewService;
use App\Domain\Intelligence\Support\EntityScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase 7 Section 38-39, 58 — human review of recommendations. Every
 * mutating action requires its own atomic permission (Section 58);
 * convert() only ever creates a Maintenance Request through the
 * existing service (Section 2).
 */
class RecommendationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntityScopeResolver $scope,
        private readonly RecommendationReviewService $review,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $allowedVehicleIds = $this->scope->allowedVehicleIds($this->context->user(), $tenantId);

        $recommendations = IntelligenceRecommendation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('entity_id', $allowedVehicleIds))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')->value()))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return $this->ok(['recommendations' => $recommendations]);
    }

    private function find(string $tenantId, string $id): IntelligenceRecommendation
    {
        $recommendation = IntelligenceRecommendation::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($id);
        abort_unless(
            $recommendation->entity_type !== 'vehicle' || $this->scope->canAccessVehicle($this->context->user(), $tenantId, $recommendation->entity_id),
            403,
            'This recommendation is outside your assigned data scope.'
        );

        return $recommendation;
    }

    public function review(Request $request, string $id)
    {
        $recommendation = $this->find($this->context->tenantId(), $id);
        $updated = $this->review->review($recommendation, $this->context->user()->id);

        return $this->ok($updated);
    }

    public function accept(Request $request, string $id)
    {
        $request->validate(['note' => 'nullable|string|max:1000']);
        $recommendation = $this->find($this->context->tenantId(), $id);

        try {
            $updated = $this->review->accept($recommendation, $this->context->user()->id, $request->string('note')->value() ?: null);
        } catch (RuntimeException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return $this->ok($updated);
    }

    public function reject(Request $request, string $id)
    {
        $request->validate(['note' => 'nullable|string|max:1000']);
        $recommendation = $this->find($this->context->tenantId(), $id);

        try {
            $updated = $this->review->reject($recommendation, $this->context->user()->id, $request->string('note')->value() ?: null);
        } catch (RuntimeException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return $this->ok($updated);
    }

    public function convert(Request $request, string $id)
    {
        $recommendation = $this->find($this->context->tenantId(), $id);

        try {
            $maintenanceRequest = $this->review->convert($recommendation, $this->context->user()->id);
        } catch (RuntimeException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return $this->ok(['maintenance_request' => $maintenanceRequest, 'recommendation' => $recommendation->fresh()]);
    }
}
