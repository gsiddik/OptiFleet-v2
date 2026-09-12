<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Services\PartnerPerformanceService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalService;
use Illuminate\Support\Facades\DB;

/**
 * G-07: a bounded record of work a Partner (e.g. TOWING_PROVIDER,
 * OTHER_SERVICE_PROVIDER, EXTERNAL_WORKSHOP) performed on behalf of a Work
 * Order. Deliberately REQUESTED -> COMPLETED/CANCELLED only — no approval
 * step and no partner-type restriction are invented here, since neither is
 * described anywhere in the source material.
 */
class WorkOrderExternalServiceService
{
    public function __construct(
        private readonly WorkOrderExecutionService $execution,
        private readonly PartnerPerformanceService $performance,
    ) {}

    public function create(WorkOrder $workOrder, Partner $partner, array $attributes, ?string $userId): WorkOrderExternalService
    {
        $this->execution->assertExecutable($workOrder);

        return WorkOrderExternalService::query()->create(array_merge($attributes, [
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
            'partner_id' => $partner->id,
            'status' => 'REQUESTED',
            'requested_by' => $userId,
            'requested_at' => now(),
        ]));
    }

    public function complete(WorkOrderExternalService $service, ?string $userId): WorkOrderExternalService
    {
        return DB::transaction(function () use ($service, $userId) {
            $locked = WorkOrderExternalService::query()->lockForUpdate()->findOrFail($service->id);
            if ($locked->status !== 'REQUESTED') {
                throw new WorkOrderException("Cannot complete an external service that is {$locked->status}.");
            }

            $locked->update(['status' => 'COMPLETED', 'completed_by' => $userId, 'completed_at' => now()]);

            $partner = Partner::query()->find($locked->partner_id);
            if ($partner) {
                $this->performance->record(
                    $partner, 'EXTERNAL_SERVICE_COMPLETED', WorkOrderExternalService::class, $locked->id,
                    null, $locked->cost !== null ? (float) $locked->cost : null,
                );
            }

            return $locked->fresh();
        });
    }

    public function cancel(WorkOrderExternalService $service, ?string $userId): WorkOrderExternalService
    {
        return DB::transaction(function () use ($service, $userId) {
            $locked = WorkOrderExternalService::query()->lockForUpdate()->findOrFail($service->id);
            if ($locked->status !== 'REQUESTED') {
                throw new WorkOrderException("Cannot cancel an external service that is {$locked->status}.");
            }

            $locked->update(['status' => 'CANCELLED', 'cancelled_by' => $userId, 'cancelled_at' => now()]);

            return $locked->fresh();
        });
    }
}
