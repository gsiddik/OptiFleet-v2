<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Identity\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateCapacityLimitRequest;

class TenantCapacityController extends Controller
{
    public function __construct(private readonly CapacityService $capacity) {}

    public function index(Tenant $tenant)
    {
        $resourceTypes = ['vehicle', 'user', 'branch', 'workshop', 'warehouse'];

        $limits = collect($resourceTypes)->map(function (string $type) use ($tenant) {
            return [
                'resource_type' => $type,
                'max_count' => $this->capacity->limitFor($tenant->id, $type),
                'current_count' => $this->capacity->currentCount($tenant->id, $type),
            ];
        });

        return $this->ok($limits);
    }

    public function update(UpdateCapacityLimitRequest $request, Tenant $tenant)
    {
        $limit = TenantCapacityLimit::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'resource_type' => $request->input('resource_type')],
            ['max_count' => $request->input('max_count')]
        );

        return $this->ok($limit);
    }
}
