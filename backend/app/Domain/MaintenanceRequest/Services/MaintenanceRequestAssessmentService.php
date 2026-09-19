<?php

namespace App\Domain\MaintenanceRequest\Services;

use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequestAssessment;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequestInspectionGroup;
use Illuminate\Support\Facades\DB;

/**
 * Initial Assessment & Visual Inspection is mutable only while the parent
 * request is DRAFT. Once the request leaves DRAFT (SUBMITTED and beyond),
 * the saved assessment becomes an immutable historical snapshot — enforced
 * here with a row lock on the parent request so a concurrent submit can't
 * race a save. Group codes/statuses are plain enums (not FKs to mutable
 * master data), so reading the same rows later always returns the exact
 * values captured at submission — no separate snapshot copy is needed.
 */
class MaintenanceRequestAssessmentService
{
    public function save(MaintenanceRequest $request, array $groups, ?string $notes, ?string $actorUserId = null): MaintenanceRequestAssessment
    {
        return DB::transaction(function () use ($request, $groups, $notes, $actorUserId) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertDraft($request);

            $assessment = MaintenanceRequestAssessment::query()->updateOrCreate(
                ['maintenance_request_id' => $request->id],
                [
                    'tenant_id' => $request->tenant_id,
                    'assessed_by' => $actorUserId,
                    'assessed_at' => now(),
                    'notes' => $notes,
                ]
            );

            $assessment->groups()->delete();
            foreach ($groups as $group) {
                MaintenanceRequestInspectionGroup::query()->create([
                    'tenant_id' => $request->tenant_id,
                    'maintenance_request_assessment_id' => $assessment->id,
                    'group_code' => $group['group_code'],
                    'status' => $group['status'],
                    'notes' => $group['notes'] ?? null,
                ]);
            }

            return $assessment->fresh('groups');
        });
    }

    public function clear(MaintenanceRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertDraft($request);

            MaintenanceRequestAssessment::query()->where('maintenance_request_id', $request->id)->delete();
        });
    }

    private function assertDraft(MaintenanceRequest $request): void
    {
        if ($request->status !== 'DRAFT') {
            throw new MaintenanceRequestException('The Initial Assessment can only be created, edited, or cleared while the request is in Draft.');
        }
    }
}
