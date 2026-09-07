<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Audit\Services\AuditService;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SyncComponentGroupsRequest;
use App\Http\Requests\Tenant\SyncVehicleCategoriesRequest;
use App\Support\TenantContext;

class MasterDataMappingController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    public function syncComponentGroups(SyncComponentGroupsRequest $request, VehicleCategory $vehicleCategory)
    {
        $this->authorizeVisible($vehicleCategory->tenant_id);

        $allowedIds = ComponentGroup::query()
            ->whereIn('id', $request->input('component_group_ids'))
            ->where(function ($q) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $this->context->tenantId());
            })
            ->pluck('id');

        $before = $vehicleCategory->componentGroups()->pluck('component_groups.id');

        $vehicleCategory->componentGroups()->sync($allowedIds);

        $this->audit->log(
            'VehicleCategoryComponentGroupMapping',
            $vehicleCategory->id,
            'updated',
            ['component_group_ids' => $before->values()->all()],
            ['component_group_ids' => $allowedIds->values()->all()],
        );

        return $this->ok($vehicleCategory->load('componentGroups'));
    }

    public function syncVehicleCategories(SyncVehicleCategoriesRequest $request, ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup->tenant_id);

        $allowedIds = VehicleCategory::query()
            ->whereIn('id', $request->input('vehicle_category_ids'))
            ->where(function ($q) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $this->context->tenantId());
            })
            ->pluck('id');

        $before = $componentGroup->vehicleCategories()->pluck('vehicle_categories.id');

        $componentGroup->vehicleCategories()->sync($allowedIds);

        $this->audit->log(
            'VehicleCategoryComponentGroupMapping',
            $componentGroup->id,
            'updated',
            ['vehicle_category_ids' => $before->values()->all()],
            ['vehicle_category_ids' => $allowedIds->values()->all()],
        );

        return $this->ok($componentGroup->load('vehicleCategories'));
    }

    private function authorizeVisible(?string $ownerTenantId): void
    {
        abort_unless($ownerTenantId === null || $ownerTenantId === $this->context->tenantId(), 404);
    }
}
