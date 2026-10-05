<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Breakdown\Services\BreakdownService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Inspection\Models\InspectionTemplate;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\MaintenancePolicy\Models\MaintenanceInterval;
use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\VehicleMaintenanceProfile;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Models\VehicleModel as VehicleModelMaster;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\QualityControl\Services\QualityControlService;
use App\Domain\QualityControl\Services\RoadTestService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\VehicleRelease\Services\VehicleReleaseService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderExecutionService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Services\LaborTimerService;
use App\Domain\Workshop\Services\MechanicAssignmentService;
use App\Domain\Workshop\Services\WorkspaceReservationService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 3 demo/dev data: vehicles, workers, workspaces, inspection
 * templates, maintenance policy/schedule, and a full maintenance flow
 * (request -> approval -> Work Order -> diagnosis/jobs -> mechanic ->
 * labor -> QC -> road test -> release) carried out through the real
 * domain services (not raw inserts), so the seeded state is exactly what
 * the same actions would produce via the API. ALPHA gets the full
 * Phase 3 module set; BETA deliberately keeps only VEHICLE entitled, to
 * exercise the "tenant without the module is denied" path (Section 1).
 */
class OperationsSeeder extends Seeder
{
    public function run(): void
    {
        $alpha = Tenant::query()->where('code', 'ALPHA')->firstOrFail();
        $beta = Tenant::query()->where('code', 'BETA')->firstOrFail();

        $truck = VehicleCategory::query()->where('code', 'VC-TRUCK')->whereNull('tenant_id')->firstOrFail();

        $this->seedAlpha($alpha, $truck);
        $this->seedBeta($beta, $truck);
    }

    private function seedAlpha(Tenant $tenant, VehicleCategory $truck): void
    {
        $jkt = Branch::query()->where('tenant_id', $tenant->id)->where('code', 'ALPHA-JKT')->firstOrFail();
        $bdg = Branch::query()->where('tenant_id', $tenant->id)->where('code', 'ALPHA-BDG')->firstOrFail();
        $jktWs = Workshop::query()->where('tenant_id', $tenant->id)->where('code', 'ALPHA-JKT-WS1')->firstOrFail();
        $bdgWs = Workshop::query()->where('tenant_id', $tenant->id)->where('code', 'ALPHA-BDG-WS1')->firstOrFail();

        // Workshop Manager: workshop-scoped user for cross-scope tests
        // (Section 48's own example — a manager who can't see the other branch's WOs).
        $wsManagerRole = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Workshop Manager')->first();
        $wsManager = User::query()->updateOrCreate(
            ['email' => 'alpha.workshopmanager@optifleet.test'],
            ['name' => 'ALPHA Workshop Manager (Jakarta)', 'password' => Hash::make('password'), 'user_type' => 'tenant', 'status' => 'active']
        );
        TenantUser::query()->firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $wsManager->id], ['status' => 'active', 'joined_at' => now()]);
        if ($wsManagerRole) {
            RoleAssignment::query()->firstOrCreate(['user_id' => $wsManager->id, 'tenant_id' => $tenant->id, 'role_id' => $wsManagerRole->id]);
        }
        DataScopeAssignment::query()->firstOrCreate([
            'user_id' => $wsManager->id, 'tenant_id' => $tenant->id, 'scope_type' => 'WORKSHOP', 'scope_resource_id' => $jktWs->id,
        ]);

        // Vehicles — linked to the tenant's Vehicle Brand / Model masters (the Product
        // compatibility of the demo Spareparts references the same masters).
        [$hinoId, $rangerFgId] = $this->vehicleMaster($tenant, 'HINO', 'Hino', 'RANGER-FG', 'Ranger FG', $truck);
        $vehicles = [];
        foreach ([
            ['reg' => 'B 1001 ALP', 'branch' => $jkt, 'ws' => $jktWs, 'odo' => 48500],
            ['reg' => 'B 1002 ALP', 'branch' => $jkt, 'ws' => $jktWs, 'odo' => 12000],
            ['reg' => 'D 2001 ALP', 'branch' => $bdg, 'ws' => $bdgWs, 'odo' => 61000],
            ['reg' => 'D 2002 ALP', 'branch' => $bdg, 'ws' => $bdgWs, 'odo' => 5000],
        ] as $i => $def) {
            $vehicles[$i] = Vehicle::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'registration_number' => $def['reg']],
                [
                    'branch_id' => $def['branch']->id,
                    'default_workshop_id' => $def['ws']->id,
                    'vehicle_category_id' => $truck->id,
                    'brand' => 'Hino', 'vehicle_brand_id' => $hinoId, 'model' => 'Ranger FG', 'vehicle_model_id' => $rangerFgId, 'vehicle_type' => 'Truck',
                    'vin' => 'VIN'.str_pad((string) $i, 14, '0', STR_PAD_LEFT).$tenant->code,
                    'chassis_number' => 'CHS'.$tenant->code.$i,
                    'year' => 2022, 'fuel_type' => 'DIESEL', 'transmission_type' => 'MANUAL',
                    'current_odometer' => $def['odo'], 'status' => 'ACTIVE', 'operational_status' => 'AVAILABLE',
                ]
            );
        }

        // Workers
        $mechanic1 = Worker::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'employee_code' => 'ALPHA-MEC-01'],
            ['name' => 'Budi Santoso', 'branch_id' => $jkt->id, 'workshop_id' => $jktWs->id, 'worker_type' => 'LEAD_MECHANIC', 'status' => 'ACTIVE']
        );
        $mechanic2 = Worker::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'employee_code' => 'ALPHA-MEC-02'],
            ['name' => 'Andi Wijaya', 'branch_id' => $jkt->id, 'workshop_id' => $jktWs->id, 'worker_type' => 'MECHANIC', 'status' => 'ACTIVE']
        );
        $qcInspector = Worker::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'employee_code' => 'ALPHA-QC-01'],
            ['name' => 'Siti Rahma', 'branch_id' => $jkt->id, 'workshop_id' => $jktWs->id, 'worker_type' => 'QC', 'status' => 'ACTIVE']
        );
        Worker::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'employee_code' => 'ALPHA-MEC-03'],
            ['name' => 'Dedi Kurnia', 'branch_id' => $bdg->id, 'workshop_id' => $bdgWs->id, 'worker_type' => 'MECHANIC', 'status' => 'ACTIVE']
        );

        $engineGroupId = ComponentGroup::query()->where('code', 'CG-ENGINE')->whereNull('tenant_id')->value('id');
        $brakeGroupId = ComponentGroup::query()->where('code', 'CG-BRAKE')->whereNull('tenant_id')->value('id');
        $tyreGroupId = ComponentGroup::query()->where('code', 'CG-TYRE')->whereNull('tenant_id')->value('id');
        foreach ([$mechanic1, $mechanic2] as $mechanic) {
            $mechanic->skills()->firstOrCreate(['component_group_id' => $engineGroupId], ['skill_level' => 4]);
            $mechanic->skills()->firstOrCreate(['component_group_id' => $brakeGroupId], ['skill_level' => 3]);
        }

        // Workspaces
        $bay1 = Workspace::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'workshop_id' => $jktWs->id, 'code' => 'JKT-BAY-1'],
            ['name' => 'Jakarta Service Bay 1', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE']
        );
        $qcBay = Workspace::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'workshop_id' => $jktWs->id, 'code' => 'JKT-QC-1'],
            ['name' => 'Jakarta QC Bay', 'workspace_type' => 'QC_BAY', 'status' => 'AVAILABLE']
        );
        Workspace::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'workshop_id' => $bdgWs->id, 'code' => 'BDG-BAY-1'],
            ['name' => 'Bandung Service Bay 1', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE']
        );
        $bay1->vehicleCategories()->sync([$truck->id]);

        // Inspection template
        $template = InspectionTemplate::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'vehicle_category_id' => $truck->id, 'inspection_type' => 'PRE_TRIP', 'name' => 'Truck Pre-Trip Checklist'],
            ['description' => 'Daily pre-trip safety checklist for trucks.', 'status' => 'ACTIVE']
        );
        if ($template->items()->count() === 0) {
            $template->items()->createMany([
                ['component_group_id' => $engineGroupId, 'item_text' => 'Engine oil level', 'input_type' => 'PASS_FAIL', 'required' => true, 'sequence' => 10],
                ['component_group_id' => $brakeGroupId, 'item_text' => 'Brake pedal response', 'input_type' => 'PASS_FAIL', 'required' => true, 'sequence' => 20],
                ['component_group_id' => $tyreGroupId, 'item_text' => 'Tyre tread depth (mm)', 'input_type' => 'NUMBER', 'required' => true, 'sequence' => 30],
                ['item_text' => 'General notes', 'input_type' => 'TEXT', 'required' => false, 'sequence' => 40],
            ]);
        }
        $templateItems = $template->items()->get();

        // Maintenance package + interval, assigned to vehicle 0
        $package = MaintenancePackage::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PM-TRUCK-10K'],
            ['name' => 'Truck Preventive Service (10,000km / 6mo)', 'maintenance_type' => 'PREVENTIVE', 'standard_labor_hours' => 3, 'status' => 'ACTIVE']
        );
        if ($package->items()->count() === 0) {
            $package->items()->create(['component_group_id' => $engineGroupId, 'service_item' => 'Engine oil & filter change', 'standard_labor_hours' => 1.5]);
        }
        if ($package->intervals()->count() === 0) {
            $package->intervals()->create(['trigger_type' => 'COMBINATION', 'odometer_km' => 10000, 'months' => 6, 'tolerance_km' => 500, 'tolerance_days' => 14]);
        }
        $profile = VehicleMaintenanceProfile::query()->firstOrCreate(
            ['vehicle_id' => $vehicles[0]->id, 'maintenance_package_id' => $package->id],
            ['tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'effective_from' => now()->subMonths(5)->toDateString()]
        );
        app(MaintenanceScheduleService::class)->generateForProfile($profile->load(['vehicle', 'package.intervals']));

        // Transactional operational history below is created once: master/setup data above is
        // updateOrCreate-idempotent, but these are real documents (numbered Work Orders,
        // inspections, breakdowns, reservations) that a rerun must never duplicate.
        if (\App\Domain\Inspection\Models\Inspection::query()->where('tenant_id', $tenant->id)->where('inspection_template_id', $template->id)->exists()) {
            return;
        }

        // Inspection on vehicle 1 that fails and raises a maintenance request
        $inspectionService = app(InspectionService::class);
        $inspection = \App\Domain\Inspection\Models\Inspection::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $jkt->id, 'workshop_id' => $jktWs->id,
            'vehicle_id' => $vehicles[1]->id, 'inspection_template_id' => $template->id,
            'inspection_type' => 'PRE_TRIP', 'status' => 'CREATED',
            'odometer_at_inspection' => $vehicles[1]->current_odometer, 'created_by' => null,
        ]);
        $inspection = $inspectionService->start($inspection);
        $inspectionService->submit($inspection, [
            ['inspection_template_item_id' => $templateItems[0]->id, 'passed' => true],
            ['inspection_template_item_id' => $templateItems[1]->id, 'passed' => false],
            ['inspection_template_item_id' => $templateItems[2]->id, 'value_number' => 4],
        ], [
            ['component_group_id' => $brakeGroupId, 'severity' => 'HIGH', 'description' => 'Brake pedal soft, needs inspection.'],
        ]);
        $requestFromInspection = app(MaintenanceRequestService::class)->create($vehicles[1], [
            'workshop_id' => $jktWs->id, 'component_group_id' => $brakeGroupId,
            'source_type' => 'INSPECTION', 'source_inspection_id' => $inspection->id,
            'priority' => 'URGENT', 'complaint' => 'Brake pedal soft, needs inspection.', 'status' => 'SUBMITTED',
        ]);

        // Full flow: request -> approve -> WO -> jobs -> mechanic -> labor -> QC -> road test -> release
        $requests = app(MaintenanceRequestService::class);
        $requests->transition($requestFromInspection, 'UNDER_REVIEW');
        $approvedRequest = $requests->transition($requestFromInspection, 'APPROVED', $wsManager->id, 'Approved for repair.');

        $workOrders = app(WorkOrderService::class);
        $wo = $workOrders->fromMaintenanceRequest($approvedRequest, ['maintenance_type' => 'CORRECTIVE'], $wsManager->id);

        // Findings/Diagnosis are a Draft-only scoping exercise (see
        // WorkOrderExecutionService::assertFindingScopeEditable) — must be
        // recorded before submit()/approve()/.../start() move the WO past Draft.
        $execution = app(WorkOrderExecutionService::class);
        $finding = $execution->addFinding($wo, ['component_group_id' => $brakeGroupId, 'severity' => 'HIGH', 'description' => 'Worn brake pads.'], $mechanic1->id);
        $execution->addDiagnosis($wo, ['work_order_finding_id' => $finding->id, 'root_cause' => 'Brake pads worn beyond limit.'], $mechanic1->id);

        $wo = $workOrders->submit($wo);
        $wo = $workOrders->approve($wo);
        $wo = $workOrders->assign($wo);
        DemoWorkspaceAssignment::approve($wo, $wsManager->id, $bay1, now());
        $wo = $workOrders->schedule($wo, $bay1->id, now(), now()->addHours(3));
        $wo = $workOrders->start($wo);

        $job = $execution->addJob($wo, ['component_group_id' => $brakeGroupId, 'service_item' => 'Replace brake pads', 'description' => 'Replace front brake pads.', 'estimated_hours' => 1.5]);

        $mechanics = app(MechanicAssignmentService::class);
        $mechanics->assign($wo, $mechanic1, 'PRIMARY', $job->id, $wsManager->id);
        $job = $execution->updateJobStatus($job, 'ASSIGNED');

        $laborTimer = app(LaborTimerService::class);
        $log = $laborTimer->start($job, $mechanic1->id);
        $laborTimer->finish($log);
        $execution->updateJobStatus($job->fresh(), 'COMPLETED');

        // G-04: WorkOrderClosureGuardService now rejects COMPLETED/CLOSED while
        // any Finding is still OPEN — resolve it through the same service the
        // API uses (WorkOrderExecutionController::resolveFinding()) once the
        // corrective work is actually done, mirroring the real user flow.
        $execution->resolveFinding($finding, 'Brake pads replaced.', $mechanic1->id);

        $wo = $workOrders->submitToQc($wo);
        $qc = app(QualityControlService::class);
        $inspectionQc = $qc->start($wo, $qcInspector->id, $wsManager->id);
        $qc->pass($inspectionQc);
        $qc->complete($inspectionQc);

        app(RoadTestService::class)->record($wo, [
            'tester_worker_id' => $qcInspector->id, 'start_odometer' => $vehicles[1]->current_odometer,
            'end_odometer' => (float) $vehicles[1]->current_odometer + 8, 'duration_minutes' => 15, 'result' => 'PASS',
        ]);

        $wo = $workOrders->complete($wo);
        app(VehicleReleaseService::class)->release($wo, [
            'release_odometer' => (float) $vehicles[1]->current_odometer + 8,
            'release_condition' => 'GOOD', 'notes' => 'Brake pads replaced, road test passed.',
        ], $wsManager->id);

        // A workspace reservation, tied to a fresh DRAFT WO left mid-pipeline
        // for list/status variety and reservation-overlap demo data.
        $wo2 = $workOrders->create($vehicles[0], [
            'workshop_id' => $jktWs->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'MEDIUM',
            'complaint' => 'Scheduled 10,000km service.',
        ], $wsManager->id);
        app(WorkspaceReservationService::class)->reserve($bay1, now()->addDay(), now()->addDay()->addHours(2), $wo2->id, $wsManager->id);

        // Breakdown on vehicle 2, taken to REPAIR_REQUIRED and converted to a request (left APPROVED, not yet a WO).
        $breakdowns = app(BreakdownService::class);
        $breakdown = $breakdowns->report($vehicles[2], [
            'location' => 'Toll road KM 45', 'severity' => 'MAJOR', 'description' => 'Engine overheating, vehicle stopped.',
        ], $mechanic1->id);
        $breakdown = $breakdowns->transition($breakdown, 'VERIFIED');
        $breakdown = $breakdowns->transition($breakdown, 'ASSESSED');
        $breakdown = $breakdowns->transition($breakdown, 'REPAIR_REQUIRED', 'Radiator hose burst, needs replacement.');
        $breakdownRequest = $breakdowns->convertToMaintenanceRequest($breakdown, [], $mechanic1->id);
        $requests->transition($breakdownRequest, 'UNDER_REVIEW');
        $requests->transition($breakdownRequest, 'APPROVED', $wsManager->id, 'Approved — urgent repair.');
    }

    private function seedBeta(Tenant $tenant, VehicleCategory $truck): void
    {
        $branch = Branch::query()->where('tenant_id', $tenant->id)->firstOrFail();
        [$mitsubishiId, $fusoId] = $this->vehicleMaster($tenant, 'MITSUBISHI', 'Mitsubishi', 'FUSO', 'Fuso', $truck);

        foreach ([
            ['reg' => 'L 3001 BET', 'odo' => 22000],
            ['reg' => 'L 3002 BET', 'odo' => 8000],
        ] as $i => $def) {
            Vehicle::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'registration_number' => $def['reg']],
                [
                    'branch_id' => $branch->id, 'vehicle_category_id' => $truck->id,
                    'brand' => 'Mitsubishi', 'vehicle_brand_id' => $mitsubishiId, 'model' => 'Fuso', 'vehicle_model_id' => $fusoId, 'vehicle_type' => 'Truck',
                    'vin' => 'VINBETA'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                    'year' => 2021, 'fuel_type' => 'DIESEL', 'transmission_type' => 'MANUAL',
                    'current_odometer' => $def['odo'], 'status' => 'ACTIVE', 'operational_status' => 'AVAILABLE',
                ]
            );
        }
    }

    /**
     * The tenant's Vehicle Brand + Model master for its demo vehicles (idempotent by code;
     * an existing row is never renamed). "Brand Of" includes the vehicles' category.
     *
     * @return array{0: string, 1: string} [vehicle_brand_id, vehicle_model_id]
     */
    private function vehicleMaster(Tenant $tenant, string $brandCode, string $brandName, string $modelCode, string $modelName, VehicleCategory $category): array
    {
        $brand = VehicleBrand::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => $brandCode],
            ['name' => $brandName, 'is_system' => false, 'status' => 'ACTIVE']
        );
        $brand->vehicleCategories()->syncWithoutDetaching([$category->id]);
        $model = VehicleModelMaster::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'vehicle_brand_id' => $brand->id, 'code' => $modelCode],
            ['name' => $modelName, 'is_system' => false, 'status' => 'ACTIVE']
        );

        return [$brand->id, $model->id];
    }
}
