<?php

namespace App\Domain\Workshop\Services;

use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Models\WorkspaceReservation;
use Illuminate\Support\Facades\DB;

/**
 * Section 32: overlap prevention via row locking rather than an
 * application-level check alone (Section 51 explicitly warns against
 * relying only on app-level locks) — every active reservation for the
 * target workspace is locked (SELECT ... FOR UPDATE) before the overlap
 * check runs, so two concurrent requests for overlapping windows on the
 * same workspace serialize on that lock instead of both reading "no
 * conflict" and both succeeding.
 */
class WorkspaceReservationService
{
    public function reserve(Workspace $workspace, \DateTimeInterface $startAt, \DateTimeInterface $endAt, ?string $workOrderId = null, ?string $createdByUserId = null): WorkspaceReservation
    {
        if ($startAt >= $endAt) {
            throw new WorkshopOpsException('Reservation start must be before its end.');
        }

        return DB::transaction(function () use ($workspace, $startAt, $endAt, $workOrderId, $createdByUserId) {
            // Lock the parent Workspace row itself (not just existing reservation rows) so two
            // concurrent reserve() calls on the same workspace always serialize — locking only the
            // reservations table can't protect against the very first overlapping pair, since with
            // no existing row to lock, SELECT ... FOR UPDATE has nothing to block on and both
            // transactions would see "no overlap" and both insert.
            Workspace::query()->where('id', $workspace->id)->lockForUpdate()->first();

            $overlap = WorkspaceReservation::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', ['RESERVED', 'ACTIVE'])
                ->where('start_at', '<', $endAt)
                ->where('end_at', '>', $startAt)
                ->exists();

            if ($overlap) {
                throw new WorkshopOpsException('This workspace already has an overlapping reservation for that time window.');
            }

            return WorkspaceReservation::query()->create([
                'tenant_id' => $workspace->tenant_id,
                'workspace_id' => $workspace->id,
                'work_order_id' => $workOrderId,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => 'RESERVED',
                'created_by' => $createdByUserId,
            ]);
        });
    }

    public function activate(WorkspaceReservation $reservation): WorkspaceReservation
    {
        if ($reservation->status !== 'RESERVED') {
            throw new WorkshopOpsException('Only a reserved slot can be activated.');
        }
        $reservation->update(['status' => 'ACTIVE']);
        Workspace::query()->where('id', $reservation->workspace_id)->update(['status' => 'OCCUPIED']);

        return $reservation->fresh();
    }

    public function complete(WorkspaceReservation $reservation): WorkspaceReservation
    {
        $reservation->update(['status' => 'COMPLETED']);
        Workspace::query()->where('id', $reservation->workspace_id)->update(['status' => 'AVAILABLE']);

        return $reservation->fresh();
    }

    public function cancel(WorkspaceReservation $reservation): WorkspaceReservation
    {
        if (in_array($reservation->status, ['COMPLETED', 'CANCELLED'], true)) {
            throw new WorkshopOpsException("Cannot cancel a reservation already {$reservation->status}.");
        }
        $reservation->update(['status' => 'CANCELLED']);

        return $reservation->fresh();
    }
}
