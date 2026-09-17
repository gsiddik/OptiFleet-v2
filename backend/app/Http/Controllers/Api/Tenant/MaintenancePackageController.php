<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MaintenancePolicy\Models\MaintenanceInterval;
use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenancePackageItem;
use App\Domain\MaintenancePolicy\Models\VehicleMaintenanceProfile;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreMaintenancePackageRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $data = $request->validated();

        // Section 10: empty/0 means "not configured" — normalized to null so
        // due-calculation never has to treat the two representations
        // differently, and PERIODIC never carries KM/engine-hour thresholds
        // (it only uses schedule_period) nor does PREVENTIVE carry a
        // schedule_period (that concept is PERIODIC-only).
        if ($data['maintenance_type'] === 'PERIODIC') {
            $data['threshold_km'] = null;
            $data['threshold_engine_hour'] = null;
        } else {
            $data['schedule_period'] = null;
        }
        foreach (['threshold_days', 'threshold_month', 'threshold_km', 'threshold_engine_hour'] as $column) {
            if (($data[$column] ?? null) === 0) {
                $data[$column] = null;
            }
        }

        $package = MaintenancePackage::query()->create($data + ['status' => 'DRAFT']);

        return $this->ok($package, 201);
    }

    public function show(MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);

        return $this->ok($maintenancePackage->load(['items.componentGroup', 'intervals', 'componentGroups']));
    }

    /**
     * Section 10-11: from DRAFT, the Draft-detail checkbox selection is
     * submitted together with Activate and saved atomically with the status
     * flip. From ARCHIVED (Inactive), it's a plain reactivation — the item
     * selection is already durably saved from the last Update Items call.
     */
    public function activate(Request $request, MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);
        abort_unless(in_array($maintenancePackage->status, ['DRAFT', 'ARCHIVED'], true), 422, 'Only a draft or inactive package can be activated.');

        DB::transaction(function () use ($request, $maintenancePackage) {
            if ($maintenancePackage->status === 'DRAFT' && $request->has('component_group_ids')) {
                $allowedIds = $this->allowedComponentGroupIds($request->input('component_group_ids', []));
                $maintenancePackage->componentGroups()->sync($allowedIds);
            }

            abort_if($maintenancePackage->componentGroups()->count() === 0, 422, 'At least one item must be selected before activating.');

            $maintenancePackage->update(['status' => 'ACTIVE']);
        });

        return $this->ok($maintenancePackage->fresh(['items.componentGroup', 'componentGroups']));
    }

    /**
     * Section 10-11: editing an already-Active package's items. If the
     * selection genuinely changed, the package becomes Inactive (Archived)
     * atomically with the item update; a no-op save leaves status untouched.
     * Schedules already generated from this package keep their own
     * package_snapshot regardless of this change.
     */
    public function updateItems(Request $request, MaintenancePackage $maintenancePackage)
    {
        $this->authorizeTenant($maintenancePackage);
        abort_unless($maintenancePackage->status === 'ACTIVE', 422, 'Only an active package\'s items can be edited this way.');

        $request->validate([
            'component_group_ids' => ['present', 'array'],
            'component_group_ids.*' => ['uuid'],
        ]);

        $allowedIds = $this->allowedComponentGroupIds($request->input('component_group_ids'))->sort()->values();
        $before = $maintenancePackage->componentGroups()->pluck('component_groups.id')->sort()->values();
        $changed = $before->all() !== $allowedIds->all();

        DB::transaction(function () use ($maintenancePackage, $allowedIds, $changed) {
            $maintenancePackage->componentGroups()->sync($allowedIds);
            if ($changed) {
                $maintenancePackage->update(['status' => 'ARCHIVED']);
            }
        });

        return $this->ok($maintenancePackage->fresh(['items.componentGroup', 'componentGroups']));
    }

    private function allowedComponentGroupIds(array $ids)
    {
        $tenantId = $this->context->tenantId();

        return ComponentGroup::query()
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->pluck('id');
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
