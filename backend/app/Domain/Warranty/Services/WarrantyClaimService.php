<?php

namespace App\Domain\Warranty\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Section 41/25: DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED ->
 * REPLACEMENT|REPAIR -> SETTLED -> CLOSED, with REJECTED as the
 * under-review side branch, now validated through the Phase 5 workflow
 * engine against the version pinned at creation. A row-locked re-check on
 * every transition (Section 51/56: "never accept ... trusted financial
 * totals" — the same discipline extends to never letting two concurrent
 * reviewers both act on one claim).
 */
class WarrantyClaimService
{
    private const RESOURCE_TYPE = 'warranty_claim';

    public function __construct(
        private readonly DocumentNumberingService $numbers,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function create(Vehicle $vehicle, array $attributes, ?string $userId): WarrantyClaim
    {
        return DB::transaction(function () use ($vehicle, $attributes, $userId) {
            $number = $this->numbers->generate('warranty_claim', $vehicle->tenant_id, $vehicle->branch_id ?? null);
            $workflowVersion = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $vehicle->tenant_id, $vehicle->branch_id ?? null);

            return WarrantyClaim::query()->create(array_merge($attributes, [
                'tenant_id' => $vehicle->tenant_id,
                'claim_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'workflow_configuration_version_id' => $workflowVersion?->id,
                'vehicle_id' => $vehicle->id,
                'status' => 'DRAFT',
            ]));
        });
    }

    public function transition(WarrantyClaim $claim, string $to, ?string $userId = null, ?string $note = null): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $to, $userId, $note) {
            $locked = WarrantyClaim::query()->lockForUpdate()->findOrFail($claim->id);

            $version = $this->workflow->resolvePinnedOrEffective($locked->workflow_configuration_version_id, self::RESOURCE_TYPE, $locked->tenant_id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $locked->status, $to)) {
                throw new WarrantyException("Cannot transition Warranty Claim from {$locked->status} to {$to}.");
            }

            $attributes = ['status' => $to];
            if (in_array($to, ['APPROVED', 'REJECTED'], true)) {
                $attributes['reviewed_by'] = $userId;
                $attributes['reviewed_at'] = now();
                $attributes['review_note'] = $note;
            }
            if ($to === 'SETTLED') {
                $attributes['settled_at'] = now();
            }

            $locked->update($attributes);

            return $locked->fresh();
        });
    }
}
