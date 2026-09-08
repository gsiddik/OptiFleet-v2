<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Intelligence\Models\IntelligenceRecommendation;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Phase 7 Section 38-39: human-in-the-loop review of a recommendation.
 * A simple documented status graph (Section 79 research: this flow is
 * system-driven/linear, not a tenant-customizable approval graph, so
 * the heavyweight Configuration WorkflowEngine is not used here) rather
 * than a generic engine. convert() is the only place a recommendation
 * ever touches an operational table, and it does so exclusively through
 * MaintenanceRequestService::create() — Section 2: no direct AI control.
 */
class RecommendationReviewService
{
    private const ALLOWED_TRANSITIONS = [
        IntelligenceRecommendation::STATUS_NEW => [IntelligenceRecommendation::STATUS_REVIEWED, IntelligenceRecommendation::STATUS_ACCEPTED, IntelligenceRecommendation::STATUS_REJECTED, IntelligenceRecommendation::STATUS_EXPIRED],
        IntelligenceRecommendation::STATUS_REVIEWED => [IntelligenceRecommendation::STATUS_ACCEPTED, IntelligenceRecommendation::STATUS_REJECTED, IntelligenceRecommendation::STATUS_EXPIRED],
        IntelligenceRecommendation::STATUS_ACCEPTED => [IntelligenceRecommendation::STATUS_CONVERTED, IntelligenceRecommendation::STATUS_EXPIRED],
    ];

    public function __construct(
        private readonly MaintenanceRequestService $maintenanceRequests,
        private readonly AuditService $audit,
    ) {}

    public function review(IntelligenceRecommendation $recommendation, string $actorUserId): IntelligenceRecommendation
    {
        return $this->transition($recommendation, IntelligenceRecommendation::STATUS_REVIEWED, $actorUserId, null, 'reviewed');
    }

    public function accept(IntelligenceRecommendation $recommendation, string $actorUserId, ?string $note = null): IntelligenceRecommendation
    {
        return $this->transition($recommendation, IntelligenceRecommendation::STATUS_ACCEPTED, $actorUserId, $note, 'accepted');
    }

    public function reject(IntelligenceRecommendation $recommendation, string $actorUserId, ?string $note = null): IntelligenceRecommendation
    {
        return $this->transition($recommendation, IntelligenceRecommendation::STATUS_REJECTED, $actorUserId, $note, 'rejected');
    }

    /** Section 39: Prediction -> Recommendation -> Human Review -> Accept -> Maintenance Request -> existing workflow. */
    public function convert(IntelligenceRecommendation $recommendation, string $actorUserId): MaintenanceRequest
    {
        if ($recommendation->status !== IntelligenceRecommendation::STATUS_ACCEPTED) {
            throw new RuntimeException('Only an ACCEPTED recommendation can be converted to a Maintenance Request.');
        }
        if ($recommendation->entity_type !== 'vehicle') {
            throw new RuntimeException('Only vehicle-entity recommendations can be converted to a Maintenance Request.');
        }

        $vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail($recommendation->entity_id);

        $request = $this->maintenanceRequests->create($vehicle, [
            'source_type' => 'INTELLIGENCE',
            'source_recommendation_id' => $recommendation->id,
            'source_prediction_id' => $recommendation->prediction_id,
            'priority' => $recommendation->priority === 'URGENT' ? 'URGENT' : ($recommendation->priority ?? 'MEDIUM'),
            'complaint' => $recommendation->description,
        ], $actorUserId);

        $recommendation->update(['status' => IntelligenceRecommendation::STATUS_CONVERTED, 'maintenance_request_id' => $request->id]);
        $this->audit->log('IntelligenceRecommendation', (string) $recommendation->id, 'converted', null, ['maintenance_request_id' => $request->id], $recommendation->tenant_id);

        return $request;
    }

    /** Scheduled sweep: recommendations past suggested_due_at/expires_at with no decision are marked EXPIRED, not silently left open forever. */
    public function expireOverdue(string $tenantId): int
    {
        $overdue = IntelligenceRecommendation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [IntelligenceRecommendation::STATUS_NEW, IntelligenceRecommendation::STATUS_REVIEWED])
            ->where('expires_at', '<', CarbonImmutable::now())
            ->get();

        foreach ($overdue as $recommendation) {
            $recommendation->update(['status' => IntelligenceRecommendation::STATUS_EXPIRED]);
        }

        return $overdue->count();
    }

    private function transition(IntelligenceRecommendation $recommendation, string $to, string $actorUserId, ?string $note, string $auditAction): IntelligenceRecommendation
    {
        $allowed = self::ALLOWED_TRANSITIONS[$recommendation->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException("Cannot transition recommendation from {$recommendation->status} to {$to}.");
        }

        $recommendation->update(['status' => $to, 'reviewed_by' => $actorUserId, 'reviewed_at' => CarbonImmutable::now(), 'review_note' => $note]);
        $this->audit->log('IntelligenceRecommendation', (string) $recommendation->id, $auditAction, null, ['status' => $to], $recommendation->tenant_id);

        return $recommendation->fresh();
    }
}
