<?php

namespace App\Domain\Workshop\Services;

use App\Domain\WorkOrder\Models\MaintenanceJob;
use App\Domain\Workshop\Models\WorkOrderLaborLog;
use Illuminate\Support\Facades\DB;

/**
 * Section 29: START -> PAUSE -> RESUME -> FINISH, with impossible
 * sequences rejected (can't pause a log that isn't running, can't resume
 * one that isn't paused, can't start a second concurrent log for the same
 * worker+job). actual_minutes excludes paused time.
 */
class LaborTimerService
{
    public function start(MaintenanceJob $job, string $workerId): WorkOrderLaborLog
    {
        return DB::transaction(function () use ($job, $workerId) {
            $open = WorkOrderLaborLog::query()
                ->where('maintenance_job_id', $job->id)
                ->where('worker_id', $workerId)
                ->whereIn('status', ['RUNNING', 'PAUSED'])
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw new WorkshopOpsException('This worker already has an open labor log for this job.');
            }

            $log = WorkOrderLaborLog::query()->create([
                'maintenance_job_id' => $job->id,
                'worker_id' => $workerId,
                'status' => 'RUNNING',
                'started_at' => now(),
                'paused_duration_minutes' => 0,
            ]);

            if ($job->status === 'ASSIGNED') {
                $job->update(['status' => 'IN_PROGRESS', 'started_at' => $job->started_at ?? now()]);
            }

            return $log;
        });
    }

    public function pause(WorkOrderLaborLog $log): WorkOrderLaborLog
    {
        if ($log->status !== 'RUNNING') {
            throw new WorkshopOpsException('Only a running labor log can be paused.');
        }

        $log->update(['status' => 'PAUSED', 'last_paused_at' => now()]);

        return $log->fresh();
    }

    public function resume(WorkOrderLaborLog $log): WorkOrderLaborLog
    {
        if ($log->status !== 'PAUSED') {
            throw new WorkshopOpsException('Only a paused labor log can be resumed.');
        }

        $elapsed = $log->last_paused_at ? (int) now()->diffInMinutes($log->last_paused_at) : 0;

        $log->update([
            'status' => 'RUNNING',
            'paused_duration_minutes' => $log->paused_duration_minutes + $elapsed,
            'last_paused_at' => null,
        ]);

        return $log->fresh();
    }

    public function finish(WorkOrderLaborLog $log): WorkOrderLaborLog
    {
        if (! in_array($log->status, ['RUNNING', 'PAUSED'], true)) {
            throw new WorkshopOpsException('Only a running or paused labor log can be finished.');
        }

        return DB::transaction(function () use ($log) {
            $pausedMinutes = $log->paused_duration_minutes;
            if ($log->status === 'PAUSED' && $log->last_paused_at) {
                $pausedMinutes += (int) now()->diffInMinutes($log->last_paused_at);
            }

            $totalMinutes = (int) $log->started_at->diffInMinutes(now());
            $actualMinutes = max(0, $totalMinutes - $pausedMinutes);

            $log->update([
                'status' => 'FINISHED',
                'completed_at' => now(),
                'paused_duration_minutes' => $pausedMinutes,
                'last_paused_at' => null,
                'actual_minutes' => $actualMinutes,
            ]);

            $job = MaintenanceJob::query()->find($log->maintenance_job_id);
            if ($job) {
                $totalActualMinutes = WorkOrderLaborLog::query()
                    ->where('maintenance_job_id', $job->id)
                    ->where('status', 'FINISHED')
                    ->sum('actual_minutes');
                $job->update(['actual_hours' => round($totalActualMinutes / 60, 2)]);
            }

            return $log->fresh();
        });
    }
}
