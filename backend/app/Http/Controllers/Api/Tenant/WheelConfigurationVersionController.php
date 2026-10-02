<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use App\Domain\Tire\Services\WheelConfigurationVersionService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Versioned wheel configurations ("New Wheels Configuration" Save). The configuration fields are
 * validated by WheelConfigurationRules inside the service; this controller only resolves the
 * vehicle category (the tenant's own or a platform category visible to it).
 */
class WheelConfigurationVersionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WheelConfigurationVersionService $service,
    ) {}

    public function index(Request $request)
    {
        $request->validate(['vehicle_category_id' => ['nullable', 'uuid']]);

        $query = WheelConfigurationVersion::query()
            ->where('tenant_id', $this->context->tenantId())
            ->with('vehicleCategory:id,code,name')
            ->orderBy('vehicle_category_id')->orderByDesc('version_number');
        if ($categoryId = $request->string('vehicle_category_id')->value()) {
            $query->where('vehicle_category_id', $categoryId);
        }
        if ($request->boolean('active_only')) {
            $query->where('status', WheelConfigurationVersion::STATUS_ACTIVE);
        }

        return $this->ok($query->get());
    }

    public function preview(Request $request)
    {
        $categoryId = $this->categoryId($request);

        return $this->ok($this->service->preview($this->context->tenantId(), $categoryId, $request->all()));
    }

    public function store(Request $request)
    {
        $categoryId = $this->categoryId($request);
        $result = $this->service->save($this->context->tenantId(), $categoryId, $request->all(), $request->user()?->id);

        return $this->ok([
            'version' => $result['version'],
            'created' => $result['created'],
            'diff' => $result['diff'],
        ], $result['created'] ? 201 : 200);
    }

    private function categoryId(Request $request): string
    {
        $request->validate([
            'vehicle_category_id' => ['required', 'uuid'],
            'config_code' => ['nullable', 'string', 'max:40'],
        ]);
        // Tenant-or-platform scope: another tenant's category is not found.
        $category = VehicleCategory::query()->find($request->string('vehicle_category_id')->value());
        if (! $category) {
            throw ValidationException::withMessages(['vehicle_category_id' => 'The selected vehicle category does not exist.']);
        }

        return $category->id;
    }
}
