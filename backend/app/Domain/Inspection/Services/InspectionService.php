<?php

namespace App\Domain\Inspection\Services;

use App\Domain\Inspection\Models\Inspection;
use App\Domain\Inspection\Models\InspectionFinding;
use App\Domain\Inspection\Models\InspectionResult;
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

            $anyFailedItem = false;
            foreach ($results as $result) {
                $passed = $result['passed'] ?? null;
                if ($passed === false) {
                    $anyFailedItem = true;
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

            if ($odometer = $inspection->odometer_at_inspection) {
                Vehicle::query()->where('id', $inspection->vehicle_id)
                    ->where('current_odometer', '<', $odometer)
                    ->update(['current_odometer' => $odometer]);
            }

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
