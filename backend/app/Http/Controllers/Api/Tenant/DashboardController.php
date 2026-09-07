<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
    ) {}

    public function index()
    {
        $tenantId = $this->context->tenantId();

        return $this->ok([
            'branches_total' => Branch::query()->count(),
            'workshops_total' => Workshop::query()->count(),
            'warehouses_total' => Warehouse::query()->count(),
            'users_total' => TenantUser::query()->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'active_modules' => $this->entitlements->activeModuleCodes($tenantId)->values(),
        ]);
    }
}
