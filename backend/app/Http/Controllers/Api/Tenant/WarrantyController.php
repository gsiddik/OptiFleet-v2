<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Services\WarrantyEligibilityService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WarrantyController extends Controller
{
    public function __construct(
        private readonly WarrantyEligibilityService $eligibility,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = Warranty::query()->where('tenant_id', $tenantId)->with(['partner', 'product', 'componentAsset', 'tire']);

        foreach (['status', 'coverage_basis', 'product_id', 'component_asset_id', 'tire_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'coverage_basis' => ['required', 'in:DATE,MILEAGE,ENGINE_HOUR,COMBINATION'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
            'duration_km' => ['nullable', 'integer', 'min:1'],
            'duration_engine_hours' => ['nullable', 'integer', 'min:1'],
            'tolerance_days' => ['nullable', 'integer', 'min:0'],
            'tolerance_km' => ['nullable', 'integer', 'min:0'],
            'tolerance_engine_hours' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['required', 'date'],
            'start_odometer' => ['nullable', 'numeric', 'min:0'],
            'start_engine_hour' => ['nullable', 'numeric', 'min:0'],
            'partner_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'component_asset_id' => ['nullable', 'uuid', 'exists:component_assets,id'],
            'tire_id' => ['nullable', 'uuid', 'exists:tires,id'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $warranty = Warranty::query()->create($validated + ['tenant_id' => $tenantId, 'status' => 'ACTIVE']);

        return $this->ok($warranty, 201);
    }

    public function show(Warranty $warranty)
    {
        $this->authorizeScope($warranty);

        return $this->ok($warranty->load(['partner', 'product', 'componentAsset', 'tire']));
    }

    public function checkEligibility(Request $request, Warranty $warranty)
    {
        $this->authorizeScope($warranty);
        $validated = $request->validate([
            'current_date' => ['nullable', 'date'],
            'current_odometer' => ['nullable', 'numeric', 'min:0'],
            'current_engine_hour' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->eligibility->evaluateWarranty(
            $warranty,
            isset($validated['current_date']) ? \Carbon\Carbon::parse($validated['current_date']) : now(),
            $validated['current_odometer'] ?? null,
            $validated['current_engine_hour'] ?? null,
        );

        return $this->ok(['status' => $result]);
    }

    public function void(Warranty $warranty)
    {
        $this->authorizeScope($warranty);
        $warranty->update(['status' => 'VOID']);

        return $this->ok($warranty->fresh());
    }

    private function authorizeScope(Warranty $warranty): void
    {
        abort_unless($warranty->tenant_id === $this->context->tenantId(), 404);
    }
}
