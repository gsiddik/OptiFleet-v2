<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateEntitlementRequest;

class TenantEntitlementController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function index(Tenant $tenant)
    {
        $entitlements = TenantModuleEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->with('module')
            ->get()
            ->map(fn (TenantModuleEntitlement $e) => [
                'id' => $e->id,
                'tenant_id' => $e->tenant_id,
                'module_id' => $e->module_id,
                'module_code' => $e->module->code,
                'module_name' => $e->module->name,
                'active' => $e->active,
                'valid_from' => $e->valid_from,
                'valid_until' => $e->valid_until,
                'source' => $e->source,
            ]);

        return $this->ok($entitlements);
    }

    public function update(UpdateEntitlementRequest $request, Tenant $tenant)
    {
        $module = Module::query()->findOrFail($request->input('module_id'));

        $attributes = [
            'valid_from' => $request->input('valid_from'),
            'valid_until' => $request->input('valid_until'),
        ];

        $entitlement = $request->boolean('active')
            ? $this->entitlements->grant($tenant->id, $module, $attributes)
            : $this->entitlements->revoke($tenant->id, $module);

        return $this->ok($entitlement);
    }
}
