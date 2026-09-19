<?php

namespace App\Domain\QualityControl\Services;

use App\Domain\QualityControl\Models\RoadTest;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderException;

class RoadTestService
{
    public function record(WorkOrder $workOrder, array $attributes): RoadTest
    {
        // Road Test is an internal-workshop-execution capability — never usable for an External
        // Work Order (Findings-only scope carried out by an external workshop).
        if ($workOrder->status === 'EXTERNAL') {
            throw new WorkOrderException('Road Test is not available for an External Work Order.');
        }

        return RoadTest::query()->create(array_merge($attributes, [
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
        ]));
    }
}
