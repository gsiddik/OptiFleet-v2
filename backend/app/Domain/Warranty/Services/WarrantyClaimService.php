<?php

namespace App\Domain\Warranty\Services;

use App\Domain\Invoice\Services\NumberSequenceService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Warranty\Models\WarrantyClaim;
use Illuminate\Support\Facades\DB;

/**
 * Section 41: DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED ->
 * REPLACEMENT|REPAIR -> SETTLED -> CLOSED, with REJECTED as the
 * under-review side branch. A row-locked re-check on every transition
 * (Section 51/56: "never accept ... trusted financial totals" — the same
 * discipline extends to never letting two concurrent reviewers both act on
 * one claim).
 */
class WarrantyClaimService
{
    private const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED'],
        'SUBMITTED' => ['UNDER_REVIEW'],
        'UNDER_REVIEW' => ['APPROVED', 'REJECTED'],
        'APPROVED' => ['REPLACEMENT', 'REPAIR'],
        'REPLACEMENT' => ['SETTLED'],
        'REPAIR' => ['SETTLED'],
        'SETTLED' => ['CLOSED'],
        'REJECTED' => ['CLOSED'],
    ];

    public function __construct(private readonly NumberSequenceService $numbers) {}

    public function create(Vehicle $vehicle, array $attributes, ?string $userId): WarrantyClaim
    {
        return DB::transaction(function () use ($vehicle, $attributes, $userId) {
            $number = sprintf('WC/%d/%06d', (int) now()->format('Y'), $this->numbers->next('warranty_claim', (int) now()->format('Y')));

            return WarrantyClaim::query()->create(array_merge($attributes, [
                'tenant_id' => $vehicle->tenant_id,
                'claim_number' => $number,
                'vehicle_id' => $vehicle->id,
                'status' => 'DRAFT',
            ]));
        });
    }

    public function transition(WarrantyClaim $claim, string $to, ?string $userId = null, ?string $note = null): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $to, $userId, $note) {
            $locked = WarrantyClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if (! in_array($to, self::TRANSITIONS[$locked->status] ?? [], true)) {
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
