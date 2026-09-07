<?php

namespace App\Domain\QualityControl\Services;

use App\Domain\QualityControl\Models\RoadTest;
use App\Domain\WorkOrder\Models\WorkOrder;

class RoadTestService
{
    public function record(WorkOrder $workOrder, array $attributes): RoadTest
    {
        return RoadTest::query()->create(array_merge($attributes, [
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
        ]));
    }
}
