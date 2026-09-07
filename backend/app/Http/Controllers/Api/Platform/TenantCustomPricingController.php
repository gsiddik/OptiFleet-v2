<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Pricing\Models\TenantCustomPricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreTenantCustomPricingRequest;

class TenantCustomPricingController extends Controller
{
    public function index(Tenant $tenant)
    {
        return $this->ok(
            TenantCustomPricing::query()->where('tenant_id', $tenant->id)->with('pricing')->latest('effective_from')->get()
        );
    }

    public function store(StoreTenantCustomPricingRequest $request, Tenant $tenant)
    {
        $pricing = TenantCustomPricing::query()->create($request->validated() + [
            'tenant_id' => $tenant->id,
            'status' => 'ACTIVE',
            'created_by' => $request->user()->id,
        ]);

        return $this->ok($pricing, 201);
    }

    public function destroy(Tenant $tenant, TenantCustomPricing $tenantCustomPricing)
    {
        abort_unless($tenantCustomPricing->tenant_id === $tenant->id, 404);

        $tenantCustomPricing->update(['status' => 'INACTIVE']);

        return $this->message('Custom pricing deactivated.');
    }
}
