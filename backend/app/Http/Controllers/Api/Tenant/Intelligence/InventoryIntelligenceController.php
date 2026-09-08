<?php

namespace App\Http\Controllers\Api\Tenant\Intelligence;

use App\Domain\Intelligence\Inventory\InventoryDemandForecastService;
use App\Domain\Intelligence\Inventory\SparePartIntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/** Phase 7 Section 32-33 — spare part consumption + demand forecast foundation. */
class InventoryIntelligenceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SparePartIntelligenceService $spareParts,
        private readonly InventoryDemandForecastService $forecast,
    ) {}

    public function index(Request $request)
    {
        $days = (int) $request->integer('days', 90);

        return $this->ok([
            'top_consumed_parts' => $this->spareParts->topConsumedParts($this->context->tenantId(), $days),
        ]);
    }

    public function forecast(Request $request)
    {
        $request->validate(['product_id' => 'required|uuid', 'warehouse_id' => 'required|uuid']);

        return $this->ok($this->forecast->forecast(
            $this->context->tenantId(),
            $request->string('product_id')->value(),
            $request->string('warehouse_id')->value(),
            (int) $request->integer('history_days', 90),
            (int) $request->integer('horizon_days', 30),
        ));
    }
}
