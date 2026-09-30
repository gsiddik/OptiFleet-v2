<?php

namespace App\Domain\Inspection\Services;

use App\Domain\Inspection\Models\Inspection;
use App\Domain\Inspection\Models\InspectionFinding;
use App\Domain\Inspection\Models\InspectionResult;
use App\Domain\Inspection\Models\InspectionTemplateItem;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Section 11: CREATED -> ASSIGNED -> STARTED -> SUBMITTED -> PASSED /
 * WARNING / FAILED. The final result is derived from submitted results and
 * recorded findings, not hand-picked by the submitter: any CRITICAL/HIGH
 * finding or a failed PASS_FAIL/CHECKBOX item fails the inspection; any
 * lower-severity finding downgrades it to WARNING; otherwise it passes.
 */
class InspectionService
{
    public function assign(Inspection $inspection, string $workerId): Inspection
    {
        if ($inspection->status !== 'CREATED') {
            throw new InspectionException('Only a created inspection can be assigned.');
        }

        $inspection->update(['status' => 'ASSIGNED', 'assigned_to' => $workerId]);

        return $inspection->fresh();
    }

    public function start(Inspection $inspection): Inspection
    {
        if (! in_array($inspection->status, ['CREATED', 'ASSIGNED'], true)) {
            throw new InspectionException('Only a created or assigned inspection can be started.');
        }

        $inspection->update(['status' => 'STARTED', 'started_at' => now()]);

        return $inspection->fresh();
    }

    public function submit(Inspection $inspection, array $results, array $findings = []): Inspection
    {
        if ($inspection->status !== 'STARTED') {
            throw new InspectionException('Only a started inspection can be submitted.');
        }

        return DB::transaction(function () use ($inspection, $results, $findings) {
            $inspection = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);

            // Section 9: the mandatory "Odometer" checklist item is the fresh
            // reading the technician actually takes during this inspection,
            // so it takes precedence as the update source over the header
            // odometer_at_inspection field (usually just defaulted at
            // creation, before the vehicle was even in front of anyone).
            $odometerItemId = InspectionTemplateItem::query()
                ->where('inspection_template_id', $inspection->inspection_template_id)
                ->where('is_system', true)
                ->where('item_text', 'Odometer')
                ->value('id');

            $anyFailedItem = false;
            $submittedOdometer = null;
            foreach ($results as $result) {
                $passed = $result['passed'] ?? null;
                if ($passed === false) {
                    $anyFailedItem = true;
                }
                if ($odometerItemId !== null && $result['inspection_template_item_id'] === $odometerItemId && isset($result['value_number'])) {
                    $submittedOdometer = $result['value_number'];
                }
                InspectionResult::query()->updateOrCreate(
                    ['inspection_id' => $inspection->id, 'inspection_template_item_id' => $result['inspection_template_item_id']],
                    [
                        'value_text' => $result['value_text'] ?? null,
                        'value_number' => $result['value_number'] ?? null,
                        'value_bool' => $result['value_bool'] ?? null,
                        'passed' => $passed,
                        'photo_path' => $result['photo_path'] ?? null,
                    ]
                );
            }

            $highestSeverity = null;
            foreach ($findings as $finding) {
                $created = InspectionFinding::query()->create(array_merge($finding, [
                    'tenant_id' => $inspection->tenant_id,
                    'inspection_id' => $inspection->id,
                    'vehicle_id' => $inspection->vehicle_id,
                ]));
                $highestSeverity = $this->maxSeverity($highestSeverity, $created->severity);
            }

            $status = match (true) {
                $anyFailedItem || in_array($highestSeverity, ['CRITICAL', 'HIGH'], true) => 'FAILED',
                in_array($highestSeverity, ['MEDIUM', 'LOW'], true) => 'WARNING',
                default => 'PASSED',
            };

            $inspection->update(['status' => $status, 'submitted_at' => now()]);

            // Section 8: locked to the same transaction, updated through the
            // Eloquent instance (not a bare Builder::update) so the change
            // goes through Vehicle's Auditable trait, and never lowers the
            // reading — a lower value is either stale data or needs a
            // dedicated correction workflow, neither of which this endpoint is.
            $odometer = $submittedOdometer ?? $inspection->odometer_at_inspection;
            if ($odometer !== null) {
                $vehicle = Vehicle::query()->lockForUpdate()->find($inspection->vehicle_id);
                if ($vehicle && $vehicle->tenant_id === $inspection->tenant_id && $odometer >= 0 && (float) $odometer > (float) $vehicle->current_odometer) {
                    $vehicle->update(['current_odometer' => $odometer]);
                }
            }

            return $inspection->fresh(['results', 'findings']);
        });
    }

    public const SUBMITTED_STATUSES = ['PASSED', 'WARNING', 'FAILED'];

    /** Records the supervisor review of a submitted inspection; its result is left as submitted. */
    public function review(Inspection $inspection, string $reviewerUserId, ?string $notes = null): Inspection
    {
        return DB::transaction(function () use ($inspection, $reviewerUserId, $notes) {
            $inspection = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);

            if (! in_array($inspection->status, self::SUBMITTED_STATUSES, true)) {
                throw new InspectionException('Only a submitted inspection can be reviewed.');
            }
            if ($inspection->reviewed_at !== null) {
                throw new InspectionException('This inspection has already been reviewed.');
            }

            $inspection->update([
                'reviewed_by' => $reviewerUserId,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            return $inspection->fresh(['results', 'findings']);
        });
    }

    private function maxSeverity(?string $current, string $candidate): string
    {
        $rank = ['INFO' => 0, 'LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];

        if ($current === null || $rank[$candidate] > $rank[$current]) {
            return $candidate;
        }

        return $current;
    }
}
