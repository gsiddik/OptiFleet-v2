<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\WheelConfiguration;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WheelConfigurationController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = WheelConfiguration::query()->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));

        if ($categoryId = $request->string('vehicle_category_id')->value()) {
            $query->where('vehicle_category_id', $categoryId);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('position_code', 'ilike', "%{$search}%")->orWhere('label', 'ilike', "%{$search}%"));
        }

        return $this->ok($query->with('vehicleCategory')->orderBy('vehicle_category_id')->orderBy('sequence')->get());
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'vehicle_category_id' => ['required', 'uuid', 'exists:vehicle_categories,id'],
            'position_code' => ['required', 'string', 'max:20'],
            'label' => ['required', 'string', 'max:100'],
            'axle_number' => ['nullable', 'integer', 'min:1'],
            'sequence' => ['nullable', 'integer', 'min:0'],
        ]);

        $config = WheelConfiguration::query()->create($validated + ['tenant_id' => $tenantId]);

        return $this->ok($config, 201);
    }

    public function update(Request $request, WheelConfiguration $wheelConfiguration)
    {
        $this->authorizeTenantOwned($wheelConfiguration);

        $validated = $request->validate([
            'position_code' => ['sometimes', 'string', 'max:20'],
            'label' => ['sometimes', 'string', 'max:100'],
            'axle_number' => ['nullable', 'integer', 'min:1'],
            'sequence' => ['nullable', 'integer', 'min:0'],
        ]);
        $wheelConfiguration->update($validated);

        return $this->ok($wheelConfiguration->fresh());
    }

    public function destroy(WheelConfiguration $wheelConfiguration)
    {
        $this->authorizeTenantOwned($wheelConfiguration);
        $wheelConfiguration->delete();

        return $this->ok(['deleted' => true]);
    }

    /**
     * Platform-default rows (tenant_id null) are a shared read-only baseline
     * layout, not tenant-editable — only a tenant's own rows may be changed.
     */
    private function authorizeTenantOwned(WheelConfiguration $wheelConfiguration): void
    {
        abort_unless($wheelConfiguration->tenant_id === $this->context->tenantId(), 404);
    }
}
