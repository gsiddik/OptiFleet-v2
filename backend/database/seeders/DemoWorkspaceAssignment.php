<?php

namespace Database\Seeders;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Models\WorkspaceReservation;
use App\Domain\Workshop\Services\WorkspaceReservationService;
use Carbon\CarbonInterface;

/**
 * Demo/functional seeders only: gives a Work Order an approved Workspace Assignment through the
 * same service the application uses (reserve → approve), so SCHEDULED → IN_PROGRESS is allowed.
 * Prefers $workspace; when it has no free capacity slot in the window, another available workspace
 * of the Work Order's workshop is used, and failing that the window moves a day later (up to two
 * weeks). Idempotent: a Work Order that already has a current assignment keeps it (a pending one
 * is approved).
 */
final class DemoWorkspaceAssignment
{
    public static function approve(WorkOrder $workOrder, ?string $userId, ?Workspace $workspace = null, ?CarbonInterface $startAt = null, int $hours = 3): WorkspaceReservation
    {
        $service = app(WorkspaceReservationService::class);
        $current = $service->current($workOrder->id);
        if ($current) {
            return $current->status === WorkspaceReservation::RESERVED && $userId ? $service->approve($current, $userId) : $current;
        }
        $start = ($startAt ?? now())->copy();
        for ($day = 0; $day < 14; $day++) {
            $from = $start->copy()->addDays($day);
            $to = $from->copy()->addHours($hours);
            $available = $service->available($workOrder, $from, $to)->pluck('workspace');
            $target = ($workspace ? $available->firstWhere('id', $workspace->id) : null) ?? $available->first();
            if ($target) {
                $reservation = $service->reserve($target, $from, $to, $workOrder->id, $userId);

                return $userId ? $service->approve($reservation, $userId) : $reservation;
            }
        }
        throw new \RuntimeException("No workspace with free capacity for Work Order {$workOrder->wo_number}.");
    }
}
