<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkerType;
use App\Domain\Workshop\Models\Workspace;

/**
 * Vehicles, Workers (mechanics/QC), and Workspaces/Service Bays — the
 * operational prerequisites every Work Order scenario needs (Section 26-28).
 * No dedicated Vehicle-creation service exists in this codebase (confirmed:
 * VehicleController::store() uses plain Eloquent create), so direct create
 * here matches the application's own pattern. Worker/Workspace are simple
 * entities with no workflow, likewise created directly.
 *
 * @return object{vehicles: array<string, Vehicle>, workers: array<string, Worker>, workspaces: array<string, Workspace>, workshop: Workshop, branch: Branch}
 */
class FunctionalTestVehicleWorkshopSeeder
{
    public function run(Tenant $tenant, object $masterData): object
    {
        $branch = Branch::query()->where('tenant_id', $tenant->id)->where('code', 'FTEST-MAIN')->firstOrFail();
        $workshop = Workshop::query()->where('tenant_id', $tenant->id)->where('code', 'FTEST-MAIN-WS1')->firstOrFail();

        $vehicles = [
            'CAR_1' => $this->makeVehicle($tenant, $branch, $workshop, $masterData, 'B 1001 TST', 'CAR', 'AVANZA', 15000),
            'CAR_2' => $this->makeVehicle($tenant, $branch, $workshop, $masterData, 'B 1002 TST', 'CAR', 'AVANZA', 42000),
            'TRUCK_1' => $this->makeVehicle($tenant, $branch, $workshop, $masterData, 'B 1003 TST', 'TRUCK', 'RANGER_FG', 68000),
            'TRUCK_2' => $this->makeVehicle($tenant, $branch, $workshop, $masterData, 'B 1004 TST', 'TRUCK', 'RANGER_FG', 5000),
            // Dedicated to the FT-WO-COMPLETED scenario only: VehicleReleaseService::release()
            // rejects a vehicle that still has ANY other non-CLOSED/CANCELLED Work Order, and
            // every other vehicle above is deliberately left permanently open (Draft/Scheduled/
            // On Hold/...) by its own status-coverage scenario — so release() needs a vehicle
            // no other scenario ever touches.
            'CAR_3' => $this->makeVehicle($tenant, $branch, $workshop, $masterData, 'B 1005 TST', 'CAR', 'AVANZA', 28000),
        ];

        $workers = [
            'LEAD_MECHANIC' => $this->makeWorker($tenant, $branch, $workshop, 'FTEST-MEC-01', '[TEST] Lead Mechanic Budi', 'LEAD_MECHANIC', 'ACTIVE'),
            'MECHANIC_1' => $this->makeWorker($tenant, $branch, $workshop, 'FTEST-MEC-02', '[TEST] Mechanic Andi', 'MECHANIC', 'ACTIVE'),
            'MECHANIC_2' => $this->makeWorker($tenant, $branch, $workshop, 'FTEST-MEC-03', '[TEST] Mechanic Citra', 'MECHANIC', 'ACTIVE'),
            'QC' => $this->makeWorker($tenant, $branch, $workshop, 'FTEST-QC-01', '[TEST] QC Inspector Dewi', 'QC', 'ACTIVE'),
            'INACTIVE_MECHANIC' => $this->makeWorker($tenant, $branch, $workshop, 'FTEST-MEC-04', '[TEST] Inactive Mechanic Eko', 'MECHANIC', 'INACTIVE'),
        ];

        $carCategoryId = $masterData->vehicleCategories['CAR']->id;
        $truckCategoryId = $masterData->vehicleCategories['TRUCK']->id;
        $busCategoryId = $masterData->vehicleCategories['BUS']->id;

        $bay1 = Workspace::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'FTEST-BAY-1'],
            ['name' => '[TEST] Service Bay 1 (Car)', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE']
        );
        $bay1->vehicleCategories()->sync([$carCategoryId]);

        $bay2 = Workspace::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'FTEST-BAY-2'],
            ['name' => '[TEST] Service Bay 2 (Truck & Bus)', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE']
        );
        $bay2->vehicleCategories()->sync([$truckCategoryId, $busCategoryId]);

        $qcBay = Workspace::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'FTEST-QC-1'],
            ['name' => '[TEST] QC Bay', 'workspace_type' => 'QC_BAY', 'status' => 'AVAILABLE']
        );
        $qcBay->vehicleCategories()->sync([$carCategoryId, $truckCategoryId, $busCategoryId]);

        return (object) [
            'vehicles' => $vehicles,
            'workers' => $workers,
            'workspaces' => ['BAY_1' => $bay1, 'BAY_2' => $bay2, 'QC_BAY' => $qcBay],
            'workshop' => $workshop,
            'branch' => $branch,
        ];
    }

    private function makeVehicle(Tenant $tenant, Branch $branch, Workshop $workshop, object $masterData, string $reg, string $categoryKey, string $modelKey, int $odometer): Vehicle
    {
        $category = $masterData->vehicleCategories[$categoryKey];
        $model = $masterData->vehicleModels[$modelKey];
        $brand = $model->brand;

        return Vehicle::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'registration_number' => $reg],
            [
                'branch_id' => $branch->id,
                'default_workshop_id' => $workshop->id,
                'vehicle_category_id' => $category->id,
                'vehicle_brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'brand' => $brand->name,
                'model' => $model->name,
                'vehicle_type' => $categoryKey === 'CAR' ? 'Car' : 'Truck',
                'vin' => 'TESTVIN'.str_pad((string) crc32($reg), 10, '0', STR_PAD_LEFT),
                'chassis_number' => 'TESTCHS'.str_replace(' ', '', $reg),
                'year' => 2023,
                'purchase_month' => 3,
                'purchase_year' => 2023,
                'fuel_type' => 'DIESEL',
                'transmission_type' => 'MANUAL',
                'current_odometer' => $odometer,
                'status' => 'ACTIVE',
                'operational_status' => 'AVAILABLE',
            ]
        );
    }

    private function makeWorker(Tenant $tenant, Branch $branch, Workshop $workshop, string $employeeCode, string $name, string $workerType, string $status): Worker
    {
        $workerTypeMaster = WorkerType::query()->where('code', $workerType)->whereNull('tenant_id')->first();

        return Worker::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'employee_code' => $employeeCode],
            [
                'name' => $name,
                'branch_id' => $branch->id,
                'workshop_id' => $workshop->id,
                'worker_type' => $workerType,
                'worker_type_id' => $workerTypeMaster?->id,
                'status' => $status,
                'hourly_rate' => 75000,
            ]
        );
    }
}
