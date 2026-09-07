<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MaintenancePolicy\Models\MaintenanceInterval;
use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenancePackageItem;
use App\Domain\MaintenancePolicy\Models\VehicleMaintenanceProfile;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreMaintenancePackageRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class MaintenancePackageController extends Controller
{
    public function __construct(
        private readonly MaintenanceScheduleService $schedules,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = MaintenancePackage::query()->with('intervals');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($type = $request->string('maintenance_type')->value()) {
            $query->where('maintenance_type', $type);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreMaintenancePackageRequest $request)
    {
        $package = MaintenancePackage::query()->create($request->validated() + ['status' => 'DRAFT']);

        return $this->ok($package, 201);
    }

    public function show(MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);

        return $this->ok($maintenancePackage->load(['items.componentGroup', 'intervals']));
    }

    public function activate(MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);
        $maintenancePackage->update(['status' => 'ACTIVE']);

        return $this->ok($maintenancePackage->fresh());
    }

    public function addItem(Request $request, MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);
        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', 'exists:component_groups,id'],
            'service_item' => ['required', 'string', 'max:255'],
            'recommended_part_reference' => ['nullable', 'string', 'max:255'],
            'standard_labor_hours' => ['nullable', 'numeric', 'min:0'],
            'checklist_template_id' => ['nullable', 'uuid', 'exists:inspection_templates,id'],
        ]);

        $item = MaintenancePackageItem::query()->create($validated + ['maintenance_package_id' => $maintenancePackage->id]);

        return $this->ok($item, 201);
    }

    public function addInterval(Request $request, MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);
        $validated = $request->validate([
            'trigger_type' => ['required', 'in:ODOMETER,ENGINE_HOUR,CALENDAR_DAY,MONTH,COMBINATION,CONDITION_BASED'],
            'odometer_km' => ['nullable', 'integer', 'min:1'],
            'engine_hours' => ['nullable', 'integer', 'min:1'],
            'calendar_days' => ['nullable', 'integer', 'min:1'],
            'months' => ['nullable', 'integer', 'min:1'],
            'tolerance_km' => ['nullable', 'integer', 'min:0'],
            'tolerance_days' => ['nullable', 'integer', 'min:0'],
            'condition_notes' => ['nullable', 'string'],
        ]);

        $interval = MaintenanceInterval::query()->create($validated + ['maintenance_package_id' => $maintenancePackage->id]);

        return $this->ok($interval, 201);
    }

    public function assignToVehicle(Request $request, MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);
        $tenantId = $this->context->tenantId();

        $request->validate(['vehicle_id' => ['required', 'uuid', 'exists:vehicles,id']]);
        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);

        $profile = VehicleMaintenanceProfile::query()->firstOrCreate(
            ['vehicle_id' => $vehicle->id, 'maintenance_package_id' => $maintenancePackage->id],
            ['tenant_id' => $tenantId, 'status' => 'ACTIVE', 'effective_from' => now()->toDateString()]
        );

        $schedule = $this->schedules->generateForProfile($profile->load(['vehicle', 'package.intervals']));

        return $this->ok(['profile' => $profile, 'schedule' => $schedule], 201);
    }

    private function authorizeTenant(MaintenancePackage $package): void
    {
        abort_unless($package->tenant_id === $this->context->tenantId(), 404);
    }
}
