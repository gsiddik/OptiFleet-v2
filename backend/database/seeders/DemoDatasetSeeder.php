<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Breakdown\Services\BreakdownService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\Inspection\Models\InspectionTemplate;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\VehicleMaintenanceProfile;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Models\VehicleModel as VehicleModelMaster;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\WarehouseBin;
use App\Domain\Organization\Models\WarehouseRack;
use App\Domain\Organization\Models\WarehouseZone;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\PurchaseReturn;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Procurement\Services\PurchaseRequestService;
use App\Domain\Procurement\Services\PurchaseReturnService;
use App\Domain\Procurement\Services\RfqService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\ProductMaster\Services\ProductCreationService;
use App\Domain\Tire\Inspection\UsedTireInspectionService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TireOperation;
use App\Domain\Tire\Models\TirePlyRating;
use App\Domain\Tire\Models\TireRuleProfile;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Models\WheelConfigurationMaster;
use App\Domain\Tire\Services\TireCycleService;
use App\Domain\Tire\Services\TireOperationService;
use App\Domain\Tire\Services\TireRegistrationService;
use App\Domain\Tire\Services\TireService;
use App\Domain\Tire\Services\VehicleTireRegistrationService;
use App\Domain\Tire\Services\VehicleWheelConfigurationMappingService;
use App\Domain\Tire\Services\WheelConfigurationMasterService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleDocument;
use App\Domain\Vehicle\Services\VehicleDocumentService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Services\WorkOrderPartRequestService;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Services\WorkspaceReservationService;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo dataset completion for the ALPHA tenant (runs after DemoDataSeeder / OperationsSeeder /
 * SupplyChainSeeder): every demo list reaches at least 5 representative records and every role
 * in the README has a login. Idempotent — each block finds its records by a stable natural key
 * (codes, registration numbers, deterministic demo serials, notes) before creating anything —
 * and every record goes through the same domain services as the application (Wheels
 * Configuration, Vehicle Mapping, tire registration, Tire Operations + Work Orders, Part
 * Requests, procurement chain), so the data is relationally valid and follows current rules.
 */
class DemoDatasetSeeder extends Seeder
{
    private Tenant $tenant;

    private User $admin;

    public function run(): void
    {
        $this->tenant = Tenant::query()->where('code', 'ALPHA')->firstOrFail();
        $this->admin = User::query()->where('email', 'alpha.admin@optifleet.test')->firstOrFail();
        $context = app(TenantContext::class);
        $previous = $context->tenantId();
        $context->setTenantId($this->tenant->id);
        try {
            $this->seedTenant();
        } finally {
            // Later seeders (e.g. the functional-test tenant) must not inherit ALPHA's scope.
            $context->setTenantId($previous);
        }
    }

    private function seedTenant(): void
    {
        $branches = $this->branches();
        $this->accessAccounts($branches);
        $this->vendors();
        $vehicles = $this->vehicles($branches);
        $carTire = $this->carTireProduct();
        $truckTire = Product::query()->where('tenant_id', $this->tenant->id)->where('name', 'Truck Tire 295/80R22.5')->firstOrFail();
        $masters = $this->wheelConfigurations();
        $this->mapAndRegisterTires($vehicles, $masters, $carTire, $truckTire);
        $this->newStockTires($carTire, $truckTire, $branches);
        $this->serviceBays($branches);
        $this->tireOperations($vehicles, $carTire, $truckTire);
        $this->procurement($truckTire);
        $this->operationalLists($branches, $vehicles);
        $this->tireRuleProfiles();
        $this->usedTires($vehicles, $truckTire);
        $this->retreadAndScrap($vehicles, $truckTire);
        $this->purchaseReturns();
        $this->vehicleDocuments($vehicles);
        $this->workspaceScheduling($branches, $vehicles);
    }

    // ------------------------------------------------------------ organisation

    /** 3 more branches (ALPHA has 5 in total), each with its workshop, warehouse and storage bin. */
    private function branches(): array
    {
        $out = [];
        foreach ([
            ['code' => 'ALPHA-SMG', 'name' => 'Semarang Branch', 'city' => 'Semarang', 'province' => 'Central Java'],
            ['code' => 'ALPHA-SBY', 'name' => 'Surabaya Branch', 'city' => 'Surabaya', 'province' => 'East Java'],
            ['code' => 'ALPHA-DPS', 'name' => 'Denpasar Branch', 'city' => 'Denpasar', 'province' => 'Bali'],
        ] as $def) {
            $branch = Branch::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'code' => $def['code']], $def + ['tenant_id' => $this->tenant->id, 'status' => 'ACTIVE']);
            $workshop = Workshop::query()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'code' => $def['code'].'-WS1'],
                ['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'name' => $branch->name.' Workshop', 'workshop_type' => 'INTERNAL', 'capacity' => 6, 'number_of_service_bays' => 3, 'status' => 'ACTIVE']
            );
            $warehouse = Warehouse::query()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'code' => $def['code'].'-WH1'],
                ['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'name' => $branch->name.' Warehouse', 'warehouse_type' => 'WORKSHOP', 'status' => 'ACTIVE']
            );
            $this->bin($warehouse);
            $out[$def['code']] = compact('branch', 'workshop', 'warehouse');
        }
        foreach (['ALPHA-JKT', 'ALPHA-BDG'] as $code) {
            $branch = Branch::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail();
            $workshop = Workshop::query()->where('tenant_id', $this->tenant->id)->where('code', $code.'-WS1')->firstOrFail();
            $warehouse = Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', $code.'-WH1')->firstOrFail();
            $this->bin($warehouse);
            $out[$code] = compact('branch', 'workshop', 'warehouse');
        }

        return $out;
    }

    private function bin(Warehouse $warehouse): WarehouseBin
    {
        $zone = WarehouseZone::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'GENERAL'], ['name' => 'General Zone', 'status' => 'ACTIVE']);
        $rack = WarehouseRack::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'warehouse_zone_id' => $zone->id, 'code' => 'GENERAL'], ['name' => 'General Rack', 'status' => 'ACTIVE']);

        return WarehouseBin::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'warehouse_rack_id' => $rack->id, 'code' => 'GENERAL'], ['name' => 'General Bin', 'status' => 'ACTIVE']);
    }

    // --------------------------------------------------------- access accounts

    /** Roles + logins for every README role not created by the earlier demo seeders. */
    private function accessAccounts(array $branches): void
    {
        $procurement = $this->role('Procurement', [
            'purchase_request.view', 'purchase_request.create', 'purchase_request.submit', 'purchase_request.approve',
            'rfq.view', 'rfq.manage', 'quotation.view', 'quotation.manage', 'quotation.select',
            'purchase_order.view', 'purchase_order.create', 'purchase_order.approve', 'purchase_order.issue', 'purchase_return.decide',
            'goods_receipt.view', 'vendor_invoice.view', 'partner.view', 'partner.manage', 'product.view', 'inventory.view', 'analytics.procurement.view',
        ]);
        $mechanic = $this->role('Mechanic', [
            'vehicle.view', 'inspection.view', 'inspection.perform', 'work_order.view', 'work_order.create', 'work_order.update',
            'diagnosis.manage', 'maintenance_job.manage', 'part_request.view', 'part_request.create', 'inventory.view', 'inventory.issue',
            'tire.view', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'rim.view', 'worker.view', 'workspace.view',
        ]);
        $qc = $this->role('QC Inspector', [
            'vehicle.view', 'work_order.view', 'qc.view', 'qc.perform', 'qc.approve', 'qc.reject', 'vehicle_release.perform',
            'inspection.view', 'inspection.review', 'tire.view', 'maintenance_history.view',
        ]);
        // Branch admin: the tenant administration of one branch — everything except tenant-wide
        // access, numbering, workflow, company and billing settings.
        $branchAdmin = $this->role('Branch Admin', Permission::query()->where('scope', 'tenant')
            ->where(fn ($q) => $q->where('name', 'not like', 'role.%')->where('name', 'not like', 'numbering.%')->where('name', 'not like', 'workflow.%')
                ->where('name', 'not like', 'account.%')->where('name', '!=', 'company.update'))
            ->pluck('name')->all());

        $this->user('alpha.procurement@optifleet.test', 'ALPHA Procurement Officer', $procurement, ['TENANT' => null]);
        $this->user('alpha.mechanic@optifleet.test', 'ALPHA Mechanic (Jakarta)', $mechanic, ['WORKSHOP' => $branches['ALPHA-JKT']['workshop']->id]);
        $this->user('alpha.qc@optifleet.test', 'ALPHA QC Inspector (Jakarta)', $qc, ['WORKSHOP' => $branches['ALPHA-JKT']['workshop']->id]);
        $this->user('alpha.branchadmin@optifleet.test', 'ALPHA Branch Admin (Bandung)', $branchAdmin, ['BRANCH' => $branches['ALPHA-BDG']['branch']->id]);
    }

    private function role(string $name, array $permissionNames): Role
    {
        $role = Role::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => $name, 'scope' => 'tenant'],
            ['is_system' => true, 'description' => $name.' role for '.$this->tenant->name]
        );
        $role->permissions()->sync(Permission::query()->where('scope', 'tenant')->whereIn('name', $permissionNames)->pluck('id')->all());

        return $role;
    }

    private function user(string $email, string $name, Role $role, array $scopes): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('password'), 'user_type' => 'tenant', 'status' => 'active']);
        TenantUser::query()->firstOrCreate(['tenant_id' => $this->tenant->id, 'user_id' => $user->id], ['status' => 'active', 'joined_at' => now()]);
        RoleAssignment::query()->firstOrCreate(['user_id' => $user->id, 'tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        foreach ($scopes as $type => $resourceId) {
            DataScopeAssignment::query()->firstOrCreate(['user_id' => $user->id, 'tenant_id' => $this->tenant->id, 'scope_type' => $type, 'scope_resource_id' => $resourceId]);
        }

        return $user;
    }

    // ----------------------------------------------------------------- vendors

    private function vendors(): void
    {
        foreach ([
            ['code' => 'VND-ANDALAN', 'name' => 'PT Andalan Parts Nusantara', 'partner_type' => 'SPARE_PART_SUPPLIER', 'contact_name' => 'Yusuf Pratama', 'contact_phone' => '024-7601122', 'payment_terms' => 'NET_30'],
            ['code' => 'VND-KARYABAN', 'name' => 'CV Karya Ban Mandiri', 'partner_type' => 'TIRE_SUPPLIER', 'contact_name' => 'Maria Simanjuntak', 'contact_phone' => '031-8803344', 'payment_terms' => 'NET_14'],
        ] as $def) {
            Partner::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'code' => $def['code']], $def + ['status' => 'ACTIVE']);
        }
    }

    // ---------------------------------------------------------------- vehicles

    /** Vehicle Brand/Model masters and the vehicles the Wheels Configurations are mapped to. */
    private function vehicles(array $branches): array
    {
        $car = VehicleCategory::query()->whereNull('tenant_id')->where('code', 'VC-CAR')->first() ?? VehicleCategory::query()->whereNull('tenant_id')->firstOrFail();
        $truck = VehicleCategory::query()->whereNull('tenant_id')->where('code', 'VC-TRUCK')->firstOrFail();
        $bus = VehicleCategory::query()->whereNull('tenant_id')->where('code', 'VC-BUS')->first() ?? $truck;
        $models = [
            'avanza' => $this->vehicleMaster('TOYOTA', 'Toyota', 'AVANZA', 'Avanza', $car),
            'granmax' => $this->vehicleMaster('DAIHATSU', 'Daihatsu', 'GRAN-MAX', 'Gran Max', $car),
            'elf' => $this->vehicleMaster('ISUZU', 'Isuzu', 'ELF-NMR', 'Elf NMR', $truck),
            'canter' => $this->vehicleMaster('MITSUBISHI', 'Mitsubishi', 'CANTER', 'Fuso Canter', $truck),
            'bus' => $this->vehicleMaster('HINO', 'Hino', 'RK8', 'RK8 Bus', $bus),
        ];

        $defs = [
            'car1' => ['reg' => 'B 3101 ALP', 'm' => 'avanza', 'cat' => $car, 'type' => 'PASSENGER_CAR', 'axles' => 2, 'wheels' => 5, 'b' => 'ALPHA-JKT', 'odo' => 23500],
            'car2' => ['reg' => 'B 3103 ALP', 'm' => 'avanza', 'cat' => $car, 'type' => 'PASSENGER_CAR', 'axles' => 2, 'wheels' => 5, 'b' => 'ALPHA-JKT', 'odo' => 18250],
            'van' => ['reg' => 'D 3102 ALP', 'm' => 'granmax', 'cat' => $car, 'type' => 'VAN', 'axles' => 2, 'wheels' => 4, 'b' => 'ALPHA-BDG', 'odo' => 41200],
            'truck1' => ['reg' => 'H 3201 ALP', 'm' => 'elf', 'cat' => $truck, 'type' => 'TRUCK', 'axles' => 2, 'wheels' => 6, 'b' => 'ALPHA-SMG', 'odo' => 87300],
            'truck2' => ['reg' => 'L 3202 ALP', 'm' => 'canter', 'cat' => $truck, 'type' => 'TRUCK', 'axles' => 3, 'wheels' => 10, 'b' => 'ALPHA-SBY', 'odo' => 64100],
            // Retread-program truck: its tires feed the Used Tire Management Retread / Scrap demo.
            'truck3' => ['reg' => 'H 3203 ALP', 'm' => 'elf', 'cat' => $truck, 'type' => 'TRUCK', 'axles' => 2, 'wheels' => 6, 'b' => 'ALPHA-SMG', 'odo' => 112400],
            'bus' => ['reg' => 'DK 3301 ALP', 'm' => 'bus', 'cat' => $bus, 'type' => 'BUS', 'axles' => 2, 'wheels' => 6, 'b' => 'ALPHA-DPS', 'odo' => 152000],
        ];
        $vehicles = [];
        foreach ($defs as $key => $d) {
            [$brandId, $modelId, $brandName, $modelName] = $models[$d['m']];
            $vehicles[$key] = Vehicle::query()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'registration_number' => $d['reg']],
                [
                    'branch_id' => $branches[$d['b']]['branch']->id, 'default_workshop_id' => $branches[$d['b']]['workshop']->id,
                    'vehicle_category_id' => $d['cat']->id, 'brand' => $brandName, 'vehicle_brand_id' => $brandId, 'model' => $modelName, 'vehicle_model_id' => $modelId,
                    'vehicle_type' => $d['type'], 'axle_count' => $d['axles'], 'wheel_count' => $d['wheels'],
                    'vin' => 'VIN'.strtoupper(substr(hash('sha1', $d['reg']), 0, 14)), 'chassis_number' => 'CHS-'.str_replace(' ', '', $d['reg']),
                    'year' => 2023, 'fuel_type' => $d['type'] === 'PASSENGER_CAR' ? 'GASOLINE' : 'DIESEL', 'transmission_type' => 'MANUAL',
                    'status' => 'ACTIVE', 'operational_status' => 'AVAILABLE',
                ]
            );
            // The odometer only moves forward (Tire Operations raise it); seed the starting reading once.
            if ((float) $vehicles[$key]->current_odometer < $d['odo']) {
                $vehicles[$key]->update(['current_odometer' => $d['odo']]);
            }
        }

        return $vehicles;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function vehicleMaster(string $brandCode, string $brandName, string $modelCode, string $modelName, VehicleCategory $category): array
    {
        $brand = VehicleBrand::query()->withoutGlobalScopes()->firstOrCreate(['tenant_id' => $this->tenant->id, 'code' => $brandCode], ['name' => $brandName, 'is_system' => false, 'status' => 'ACTIVE']);
        $brand->vehicleCategories()->syncWithoutDetaching([$category->id]);
        $model = VehicleModelMaster::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'vehicle_brand_id' => $brand->id, 'code' => $modelCode],
            ['name' => $modelName, 'is_system' => false, 'status' => 'ACTIVE']
        );

        return [$brand->id, $model->id, $brand->name, $model->name];
    }

    private function carTireProduct(): Product
    {
        $name = 'Passenger Tire 185/65R15';
        $existing = Product::query()->where('tenant_id', $this->tenant->id)->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }
        $group = ComponentGroup::query()->whereNull('tenant_id')->where('code', 'CG-TYRE')->firstOrFail();
        $category = ComponentCategory::query()->whereNull('tenant_id')->where('component_group_id', $group->id)->where('code', 'PASSENGER_TIRE')->firstOrFail();
        $load = TireLoadIndex::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'code' => 'LI-88'], ['max_load_single_kg' => 560, 'is_system' => false, 'status' => 'ACTIVE']);
        $speed = TireSpeedRating::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'code' => 'H'], ['max_speed_kmh' => 210, 'is_system' => false, 'status' => 'ACTIVE']);
        $jkt = Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', 'ALPHA-JKT-WH1')->firstOrFail();

        return app(ProductCreationService::class)->createFromInput($this->tenant->id, [
            'name' => $name, 'product_type' => 'TIRE', 'brand' => 'Bridgestone', 'track_serial_number' => true,
            'product_category_id' => ProductCategory::query()->whereNull('tenant_id')->where('code', 'PC-TIRE')->firstOrFail()->id,
            'uom_id' => Uom::query()->whereNull('tenant_id')->where('code', 'PCS')->firstOrFail()->id,
            'default_storage_bin_id' => $this->bin($jkt)->id,
            'reference_tread_depth_mm' => '8.00',
            'component_group_id' => $group->id, 'component_category_id' => $category->id,
            'spec' => [
                'vehicle_group' => 'CAR', 'pattern_name' => 'Ecopia EP150', 'width_mm' => 185, 'aspect_ratio_percent' => 65, 'construction_type' => 'RADIAL',
                'rim_diameter_inch' => 15, 'tire_type' => 'TUBELESS', 'single_load_index_id' => $load->id, 'speed_rating_id' => $speed->id,
            ],
        ]);
    }

    // ----------------------------------------------------- wheels configuration

    /** 5 Wheels Configurations across vehicle types. */
    private function wheelConfigurations(): array
    {
        $service = app(WheelConfigurationMasterService::class);
        $defs = [
            'car' => ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => [1], 'spare_tires' => 1],
            'van' => ['vehicle_type' => 'VAN', 'front_axles' => [1], 'rear_axles' => [1], 'spare_tires' => 0],
            'truck' => ['vehicle_type' => 'TRUCK', 'truck_configuration_type' => 'NON_TRAILER', 'front_axles' => [1], 'rear_axles' => [2], 'spare_tires' => 0],
            'truck_tandem' => ['vehicle_type' => 'TRUCK', 'truck_configuration_type' => 'NON_TRAILER', 'front_axles' => [1], 'rear_axles' => [2, 2], 'spare_tires' => 0],
            'bus' => ['vehicle_type' => 'BUS', 'front_axles' => [1], 'rear_axles' => [2], 'spare_tires' => 0],
        ];
        $masters = [];
        foreach ($defs as $key => $input) {
            $preview = $service->preview($this->tenant->id, $input);
            $masters[$key] = $preview['duplicate']
                ? WheelConfigurationMaster::query()->findOrFail($preview['duplicate']['id'])
                : $service->create($this->tenant->id, $input + ['config_code' => $preview['configuration']['config_code']], $this->admin->id)['master'];
        }

        return $masters;
    }

    /** Map the demo vehicles and register the tire on every position (spares included). */
    private function mapAndRegisterTires(array $vehicles, array $masters, Product $carTire, Product $truckTire): void
    {
        $mapping = app(VehicleWheelConfigurationMappingService::class);
        $plan = ['car1' => 'car', 'car2' => 'car', 'van' => 'van', 'truck1' => 'truck', 'truck2' => 'truck_tandem', 'truck3' => 'truck', 'bus' => 'bus'];
        foreach ($plan as $vehicleKey => $masterKey) {
            $vehicle = $vehicles[$vehicleKey];
            $mapped = VehicleWheelConfigurationMapping::query()->where('vehicle_id', $vehicle->id)->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)->exists();
            if (! $mapped) {
                $mapping->save($masters[$masterKey], [$vehicle->id], [], [], $this->admin);
            }
        }

        $registrations = app(VehicleTireRegistrationService::class);
        foreach (array_keys($plan) as $vehicleKey) {
            $vehicle = $vehicles[$vehicleKey]->fresh();
            $product = in_array($vehicleKey, ['car1', 'car2', 'van'], true) ? $carTire : $truckTire;
            $context = app(TireOperationService::class)->context($vehicle);
            $installKm = (string) ((int) $vehicle->current_odometer - 6000);
            foreach ($context['positions'] as $i => $position) {
                if ($position['tire']) {
                    continue;
                }
                $registrations->register($vehicle, [
                    'position_code' => $position['position_code'], 'installed_date' => now()->subMonths(3)->toDateString(), 'installed_time' => '08:00',
                    'installation_km' => $installKm, 'product_id' => $product->id,
                    'serial_number' => DemoSerial::make("{$vehicle->registration_number}|{$position['position_code']}"),
                    'tread_depth_mm' => (string) (9 - ($i % 3) * 0.5),
                ], $this->admin->id);
            }
        }
    }

    /** New Stock serials of both tire products (Tire Detail → New Stock, Replacing With options). */
    private function newStockTires(Product $carTire, Product $truckTire, array $branches): void
    {
        $tires = app(TireRegistrationService::class);
        foreach ([[$carTire, 6, 'ALPHA-JKT'], [$truckTire, 6, 'ALPHA-SMG']] as [$product, $count, $branch]) {
            for ($n = 1; $n <= $count; $n++) {
                $serial = DemoSerial::make("ALPHA|NEW|{$product->name}|{$n}");
                if (Tire::query()->where('tenant_id', $this->tenant->id)->where('serial_number', $serial)->exists()) {
                    continue;
                }
                $tire = $tires->register($this->tenant->id, [
                    'product_id' => $product->id, 'serial_number' => $serial,
                    'manufacture_date_code' => sprintf('%02d26', 10 + $n), 'purchase_date' => now()->subDays(20 + $n)->toDateString(),
                ]);
                $tire->update(['current_warehouse_id' => $branches[$branch]['warehouse']->id]);
            }
        }
        // Warehouse quantity for the tire products, so replacements can be issued.
        $inventory = app(InventoryService::class);
        foreach ([[$carTire, 'ALPHA-JKT', 650000], [$truckTire, 'ALPHA-SMG', 3250000], [$truckTire, 'ALPHA-JKT', 3250000]] as [$product, $branch, $cost]) {
            $warehouse = $branches[$branch]['warehouse'];
            $onHand = (float) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_on_hand');
            if ($onHand < 6) {
                $inventory->receive($warehouse, $product, 6, $cost, 'OPENING', null, null, $this->admin->id);
            }
        }
    }

    // --------------------------------------------------------- tire operations

    /**
     * One general service bay (capacity 2) per branch workshop, created before any Work Order is
     * scheduled: SCHEDULED → IN_PROGRESS needs an approved Workspace Assignment.
     */
    private function serviceBays(array $branches): void
    {
        foreach ($branches as $code => $branch) {
            Workspace::query()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'workshop_id' => $branch['workshop']->id, 'code' => "{$code}-SVC-1"],
                ['name' => $branch['branch']->name.' Service Bay', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'capacity' => 2, 'capacity_unit' => 'vehicles', 'status' => 'AVAILABLE']
            );
        }
    }

    /** One operation per status and type, each with its Work Order. */
    private function tireOperations(array $vehicles, Product $carTire, Product $truckTire): void
    {
        $operations = app(TireOperationService::class);
        $workOrders = app(WorkOrderService::class);
        $date = fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString();
        $exists = fn (Vehicle $v, string $type) => TireOperation::query()->where('vehicle_id', $v->id)->where('operation_type', $type)->exists();
        // SCHEDULED → IN_PROGRESS needs an approved Workspace Assignment (reserve → approve).
        $start = function (WorkOrder $wo) use ($workOrders) {
            $wo = $workOrders->assign($workOrders->approve($workOrders->submit($wo)));
            DemoWorkspaceAssignment::approve($wo, $this->admin->id);

            return $workOrders->start($workOrders->schedule($wo));
        };
        $free = fn (Product $p) => collect($operations->replacementCandidates($this->tenant->id, $p->id))->where('source', 'NEW_STOCK')->pluck('id')->values();

        // 1. Replacement — NEW (requested replacement tire waiting for the Work Order).
        if (! $exists($vehicles['car1'], 'REPLACEMENT')) {
            $operations->create($vehicles['car1']->fresh(), [
                'operation_type' => 'REPLACEMENT', 'operated_date' => $date(1), 'operated_time' => '09:30', 'odometer' => '23600',
                'items' => [['position_code' => '1FL1', 'replacement_tire_id' => $free($carTire)[0]]],
            ], $this->admin->id);
        }

        // 2. Replacement — IN PROGRESS, tires approved, issued and consumed (installed).
        if (! $exists($vehicles['truck2'], 'REPLACEMENT')) {
            $candidates = $free($truckTire);
            $op = $operations->create($vehicles['truck2']->fresh(), [
                'operation_type' => 'REPLACEMENT', 'operated_date' => $date(3), 'operated_time' => '10:15', 'odometer' => '64300',
                'items' => [['position_code' => '1FL1', 'replacement_tire_id' => $candidates[0]], ['position_code' => '1FR1', 'replacement_tire_id' => $candidates[1]]],
            ], $this->admin->id);
            $wo = $start(WorkOrder::query()->findOrFail($op->work_order_id));
            $requests = app(WorkOrderPartRequestService::class);
            $request = WorkOrderPartRequest::query()->where('tire_operation_id', $op->id)->firstOrFail();
            $request = $requests->approve($request, null, $this->admin->id, 'Approved for tire replacement');
            // Issued from Semarang, which holds the truck tire stock (Issue deducts it).
            $request = $requests->issue($request, Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', 'ALPHA-SMG-WH1')->firstOrFail(), $this->admin->id);
            $part = $request->items->first()->plannedPart;
            app(WorkOrderPartService::class)->consume($part, null, $this->admin->id);
        }

        // 3. Rotation — IN PROGRESS (two pairs, applied when the Work Order completes).
        if (! $exists($vehicles['truck1'], 'ROTATION')) {
            $op = $operations->create($vehicles['truck1']->fresh(), [
                'operation_type' => 'ROTATION', 'operated_date' => $date(2), 'operated_time' => '13:00', 'odometer' => '87450',
                'rotation_pairs' => [['from' => '1FL1', 'to' => '1RL1'], ['from' => '1FR1', 'to' => '1RR1']],
            ], $this->admin->id);
            $start(WorkOrder::query()->findOrFail($op->work_order_id));
        }

        // 4. Inspection — COMPLETED (tread depths recorded on completion; usage starts here).
        if (! $exists($vehicles['bus'], 'INSPECTION')) {
            $context = $operations->context($vehicles['bus']->fresh());
            $op = $operations->create($vehicles['bus']->fresh(), [
                'operation_type' => 'INSPECTION', 'operated_date' => $date(5), 'operated_time' => '08:45', 'odometer' => '152400',
                'items' => collect($context['positions'])->values()->map(fn ($p, $i) => ['position_code' => $p['position_code'], 'tread_depth_mm' => (string) (7.5 - ($i % 4) * 0.5)])->all(),
            ], $this->admin->id);
            $workOrders->complete($workOrders->submitToQc($start(WorkOrder::query()->findOrFail($op->work_order_id))));
        }

        // 5. Inspection — NEW (all positions selected, spare included).
        if (! $exists($vehicles['van'], 'INSPECTION')) {
            $context = $operations->context($vehicles['van']->fresh());
            $operations->create($vehicles['van']->fresh(), [
                'operation_type' => 'INSPECTION', 'operated_date' => $date(1), 'operated_time' => '07:15', 'odometer' => '41300',
                'items' => collect($context['positions'])->map(fn ($p) => ['position_code' => $p['position_code']])->values()->all(),
            ], $this->admin->id);
        }

        // 6. Rotation — CANCELLED (operation and its Work Order cancelled together).
        if (! $exists($vehicles['car2'], 'ROTATION')) {
            $op = $operations->create($vehicles['car2']->fresh(), [
                'operation_type' => 'ROTATION', 'operated_date' => $date(4), 'operated_time' => '11:20', 'odometer' => '18300',
                'rotation_pairs' => [['from' => '1FL1', 'to' => '1RR1']],
            ], $this->admin->id);
            $operations->cancel($op, 'Rescheduled after the customer visit.', $this->admin->id);
        }
    }

    // ------------------------------------------------------------- procurement

    /** PR → RFQ → quotation → PO → goods receipt until ALPHA has 5 complete chains. */
    private function procurement(Product $truckTire): void
    {
        $chains = [
            ['note' => 'Demo restock: brake pads for Q4 services.', 'product' => 'Brake Pad Set', 'qty' => 40, 'price' => 425000, 'vendor' => 'VND-ANDALAN', 'wh' => 'ALPHA-SMG-WH1', 'received' => 40, 'invoice' => 'INV-APN-2026-0101'],
            ['note' => 'Demo restock: truck tires for the Semarang fleet.', 'product' => $truckTire->name, 'qty' => 12, 'price' => 3250000, 'vendor' => 'VND-KARYABAN', 'wh' => 'ALPHA-SMG-WH1', 'received' => 12, 'invoice' => 'INV-KBM-2026-0102'],
            ['note' => 'Demo restock: batteries for Surabaya.', 'product' => 'Truck Battery 12V 100Ah', 'qty' => 8, 'price' => 1800000, 'vendor' => 'VND-SINAR', 'wh' => 'ALPHA-SBY-WH1', 'received' => 5, 'invoice' => 'INV-SSC-2026-0103'],
            ['note' => 'Demo restock: oil filters for Denpasar.', 'product' => 'Oil Filter', 'qty' => 30, 'price' => 85000, 'vendor' => 'VND-MITRA', 'wh' => 'ALPHA-DPS-WH1', 'received' => 30, 'invoice' => 'INV-MUS-2026-0104'],
        ];
        foreach ($chains as $chain) {
            if (! PurchaseRequest::query()->where('tenant_id', $this->tenant->id)->where('notes', $chain['note'])->exists()) {
                $this->purchaseChain($chain);
            }
        }
    }

    /** One PR → RFQ → quotation → PO → goods receipt chain; null when its masters are missing. */
    private function purchaseChain(array $chain): ?PurchaseOrder
    {
        $product = Product::query()->where('tenant_id', $this->tenant->id)->where('name', 'ilike', '%'.$chain['product'].'%')->first();
        $vendor = Partner::query()->where('tenant_id', $this->tenant->id)->where('code', $chain['vendor'])->first();
        $warehouse = Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', $chain['wh'])->first();
        if (! $product || ! $vendor || ! $warehouse) {
            return null;
        }
        $prService = app(PurchaseRequestService::class);
        $pr = $prService->create($warehouse, ['source_type' => 'MANUAL', 'priority' => 'MEDIUM', 'notes' => $chain['note']], [
            ['product_id' => $product->id, 'requested_quantity' => $chain['qty'], 'estimated_unit_price' => $chain['price']],
        ], $this->admin->id);
        foreach (['SUBMITTED', 'UNDER_REVIEW', 'APPROVED'] as $to) {
            $pr = $prService->transition($pr, $to);
        }
        $rfqService = app(RfqService::class);
        $rfq = $rfqService->inviteVendors($rfqService->create($warehouse, [], [['product_id' => $product->id, 'quantity' => $chain['qty']]], $pr), [$vendor->id]);
        $quotation = $rfqService->selectVendor($rfqService->submitQuotation($rfq, $vendor, ['lead_time_days' => 7, 'payment_terms' => $vendor->payment_terms ?? 'NET_30'], [
            ['product_id' => $product->id, 'quantity' => $chain['qty'], 'unit_price' => $chain['price'], 'tax_percent' => 11],
        ], DemoQuotationDocument::make($vendor->name), $this->admin->id));
        $poService = app(PurchaseOrderService::class);
        $po = $poService->createFromQuotation($quotation, $warehouse, ['order_date' => now()->toDateString()], $this->admin->id);
        $po = $poService->transition($poService->approve($poService->transition($po, 'SUBMITTED'), $this->admin->id), 'ISSUED');
        $amount = number_format($chain['received'] * $chain['price'] * 1.11, 2, '.', '');
        app(GoodsReceiptService::class)->post($po, $warehouse, [
            ['purchase_order_item_id' => $po->items()->first()->id, 'quantity_accepted' => $chain['received']],
        ], $this->admin->id, $chain['received'] < $chain['qty'] ? 'Partial delivery; remainder backordered.' : 'Delivered in full.', [
            'mode' => 'NEW', 'vendor_invoice_number' => $chain['invoice'], 'vendor_invoice_date' => now()->toDateString(),
            'amount' => $amount, 'terms_of_payment_days' => 30,
            'document' => DemoQuotationDocument::make($chain['invoice'], 'Demo vendor invoice', $chain['invoice'].'.pdf'),
        ]);

        return $po->fresh();
    }

    // ------------------------------------------------------------ operational lists

    /**
     * Tops the workshop / maintenance lists that OperationsSeeder starts (one reference flow each)
     * up to at least 5 records: workers, workspaces, inspection templates, maintenance packages
     * (+ vehicle profiles and their generated schedules), breakdowns, maintenance requests,
     * inspections and the tenant tire specification masters. Masters are keyed by code; the
     * transactional records (breakdowns, requests, inspections) are found by their description
     * text before anything is created, so a re-run never duplicates them.
     */
    private function operationalLists(array $branches, array $vehicles): void
    {
        $tenantId = $this->tenant->id;
        $groups = ComponentGroup::query()->whereNull('tenant_id')->whereIn('code', ['CG-ENGINE', 'CG-BRAKE', 'CG-TYRE'])->pluck('id', 'code');

        foreach ([
            ['ALPHA-MEC-04', 'Rudi Hartono', 'ALPHA-SMG', 'MECHANIC'],
            ['ALPHA-TEC-01', 'Wayan Sudarma', 'ALPHA-DPS', 'TECHNICIAN'],
            ['ALPHA-INS-01', 'Rina Kusuma', 'ALPHA-SBY', 'INSPECTOR'],
        ] as [$code, $name, $branch, $type]) {
            Worker::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'employee_code' => $code],
                ['name' => $name, 'branch_id' => $branches[$branch]['branch']->id, 'workshop_id' => $branches[$branch]['workshop']->id, 'worker_type' => $type, 'status' => 'ACTIVE']
            );
        }

        foreach ([
            ['ALPHA-SMG', 'SMG-BAY-1', 'Semarang Service Bay 1', 'GENERAL_SERVICE_BAY'],
            ['ALPHA-SBY', 'SBY-TIRE-1', 'Surabaya Tire Bay', 'TIRE_BAY'],
            ['ALPHA-DPS', 'DPS-INSP-1', 'Denpasar Inspection Bay', 'INSPECTION_BAY'],
        ] as [$branch, $code, $name, $type]) {
            Workspace::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'workshop_id' => $branches[$branch]['workshop']->id, 'code' => $code],
                ['name' => $name, 'workspace_type' => $type, 'status' => 'AVAILABLE']
            );
        }

        $category = fn (string $code) => VehicleCategory::query()->whereNull('tenant_id')->where('code', $code)->value('id')
            ?? VehicleCategory::query()->whereNull('tenant_id')->where('code', 'VC-TRUCK')->value('id');
        $templates = [];
        foreach ([
            ['VC-TRUCK', 'POST_TRIP', 'Truck Post-Trip Checklist'],
            ['VC-CAR', 'PERIODIC', 'Passenger Car Monthly Check'],
            ['VC-BUS', 'WORKSHOP', 'Bus Workshop Intake Check'],
            ['VC-TRUCK', 'MAINTENANCE', 'Truck Tire & Brake Maintenance Check'],
        ] as [$cat, $type, $name]) {
            $template = InspectionTemplate::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'vehicle_category_id' => $category($cat), 'inspection_type' => $type, 'name' => $name],
                ['description' => $name.'.', 'status' => 'ACTIVE']
            );
            if ($template->items()->count() === 0) {
                $template->items()->createMany([
                    ['component_group_id' => $groups['CG-TYRE'] ?? null, 'item_text' => 'Tire condition & pressure', 'input_type' => 'PASS_FAIL', 'required' => true, 'sequence' => 10],
                    ['component_group_id' => $groups['CG-BRAKE'] ?? null, 'item_text' => 'Brake function', 'input_type' => 'PASS_FAIL', 'required' => true, 'sequence' => 20],
                    ['item_text' => 'Notes', 'input_type' => 'TEXT', 'required' => false, 'sequence' => 30],
                ]);
            }
            $templates[$type] = $template;
        }

        $schedules = app(MaintenanceScheduleService::class);
        foreach ([
            ['PM-TRUCK-20K', 'Truck Major Service (20,000km / 12mo)', 'PREVENTIVE', 20000, 12, 'truck1'],
            ['PM-CAR-5K', 'Passenger Car Service (5,000km / 3mo)', 'PREVENTIVE', 5000, 3, 'car1'],
            ['PM-VAN-10K', 'Van Periodic Service (10,000km / 6mo)', 'PERIODIC', 10000, 6, 'van'],
            ['PM-BUS-15K', 'Bus Preventive Service (15,000km / 6mo)', 'PREVENTIVE', 15000, 6, 'bus'],
        ] as [$code, $name, $type, $km, $months, $vehicleKey]) {
            $package = MaintenancePackage::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                ['name' => $name, 'maintenance_type' => $type, 'standard_labor_hours' => 2, 'status' => 'ACTIVE']
            );
            if ($package->items()->count() === 0) {
                $package->items()->create(['component_group_id' => $groups['CG-ENGINE'] ?? null, 'service_item' => 'Engine oil & filter change', 'standard_labor_hours' => 1]);
                $package->items()->create(['component_group_id' => $groups['CG-TYRE'] ?? null, 'service_item' => 'Tire rotation & tread check', 'standard_labor_hours' => 1]);
            }
            if ($package->intervals()->count() === 0) {
                $package->intervals()->create(['trigger_type' => 'COMBINATION', 'odometer_km' => $km, 'months' => $months, 'tolerance_km' => 500, 'tolerance_days' => 14]);
            }
            $profile = VehicleMaintenanceProfile::query()->firstOrCreate(
                ['vehicle_id' => $vehicles[$vehicleKey]->id, 'maintenance_package_id' => $package->id],
                ['tenant_id' => $tenantId, 'status' => 'ACTIVE', 'effective_from' => now()->subMonths(2)->toDateString()]
            );
            if ($profile->wasRecentlyCreated) {
                $schedules->generateForProfile($profile->load(['vehicle', 'package.intervals']));
            }
        }

        // Breakdowns at different stages; the two resolved ones return their vehicle to service.
        $breakdowns = app(BreakdownService::class);
        $legacy = fn (string $reg) => Vehicle::query()->where('tenant_id', $tenantId)->where('registration_number', $reg)->first();
        foreach ([
            [$legacy('D 2002 ALP'), 'MINOR', 'Jl. Soekarno-Hatta, Bandung', 'Flat front-left tire on delivery route.', []],
            [$legacy('B 1002 ALP'), 'MAJOR', 'Cikampek toll KM 72', 'Alternator warning light, battery not charging.', ['VERIFIED']],
            [$vehicles['truck1'], 'MAJOR', 'Pantura KM 120, Kendal', 'Air brake pressure dropping.', ['VERIFIED', 'ASSESSED', 'RESOLVED']],
            [$vehicles['bus'], 'MINOR', 'Terminal Mengwi', 'Door actuator stuck, passengers moved to standby bus.', ['VERIFIED', 'ASSESSED', 'RESOLVED']],
        ] as [$vehicle, $severity, $location, $description, $steps]) {
            if (! $vehicle || Breakdown::query()->where('tenant_id', $tenantId)->where('description', $description)->exists()) {
                continue;
            }
            $breakdown = $breakdowns->report($vehicle, ['location' => $location, 'severity' => $severity, 'description' => $description], $this->admin->id);
            foreach ($steps as $step) {
                $breakdown = $breakdowns->transition($breakdown, $step, $step === 'RESOLVED' ? 'Fixed on site by the mobile mechanic.' : null);
            }
        }

        $requests = app(MaintenanceRequestService::class);
        foreach ([
            ['car1', 'ALPHA-JKT', 'CG-BRAKE', 'MEDIUM', 'Squeaking noise when braking at low speed.', 'SUBMITTED'],
            ['van', 'ALPHA-BDG', 'CG-ENGINE', 'LOW', 'Engine idle slightly rough in the morning.', 'DRAFT'],
            ['bus', 'ALPHA-DPS', 'CG-TYRE', 'HIGH', 'Rear tires wearing unevenly, request alignment check.', 'SUBMITTED'],
        ] as [$vehicleKey, $branch, $group, $priority, $complaint, $status]) {
            if (MaintenanceRequest::query()->where('tenant_id', $tenantId)->where('complaint', $complaint)->exists()) {
                continue;
            }
            $requests->create($vehicles[$vehicleKey], [
                'workshop_id' => $branches[$branch]['workshop']->id, 'component_group_id' => $groups[$group] ?? null,
                'source_type' => 'USER', 'priority' => $priority, 'complaint' => $complaint, 'status' => $status,
            ], $this->admin->id);
        }

        // Inspections: two waiting to start, two submitted (all checks passed).
        $inspections = app(InspectionService::class);
        foreach ([
            ['truck1', 'POST_TRIP', 'ALPHA-SMG', false],
            ['car2', 'PERIODIC', 'ALPHA-JKT', false],
            ['bus', 'WORKSHOP', 'ALPHA-DPS', true],
            ['truck2', 'MAINTENANCE', 'ALPHA-SBY', true],
        ] as [$vehicleKey, $type, $branch, $submit]) {
            $template = $templates[$type];
            $vehicle = $vehicles[$vehicleKey];
            if (Inspection::query()->where('tenant_id', $tenantId)->where('inspection_template_id', $template->id)->where('vehicle_id', $vehicle->id)->exists()) {
                continue;
            }
            $inspection = Inspection::query()->create([
                'tenant_id' => $tenantId, 'branch_id' => $branches[$branch]['branch']->id, 'workshop_id' => $branches[$branch]['workshop']->id,
                'vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id, 'inspection_type' => $type, 'status' => 'CREATED',
                'odometer_at_inspection' => $vehicle->current_odometer, 'created_by' => $this->admin->id,
            ]);
            if ($submit) {
                $inspection = $inspections->start($inspection);
                $inspections->submit($inspection, $template->items()->where('input_type', 'PASS_FAIL')->get()
                    ->map(fn ($item) => ['inspection_template_item_id' => $item->id, 'passed' => true])->all());
            }
        }

        // Tenant tire specification masters (the global system list is separate).
        foreach ([['LI-91', 615], ['LI-146', 3000], ['LI-150', 3350], ['LI-121', 1450]] as [$code, $kg]) {
            TireLoadIndex::query()->updateOrCreate(['tenant_id' => $tenantId, 'code' => $code], ['max_load_single_kg' => $kg, 'is_system' => false, 'status' => 'ACTIVE']);
        }
        foreach ([['T', 190], ['V', 240], ['L', 120], ['W', 270]] as [$code, $kmh]) {
            TireSpeedRating::query()->updateOrCreate(['tenant_id' => $tenantId, 'code' => $code], ['max_speed_kmh' => $kmh, 'is_system' => false, 'status' => 'ACTIVE']);
        }
        foreach ([['4PR', 'B'], ['6PR', 'C'], ['8PR', 'D'], ['16PR', 'H'], ['12PR', 'F']] as [$code, $range]) {
            TirePlyRating::query()->updateOrCreate(['tenant_id' => $tenantId, 'code' => $code], ['load_range' => $range, 'is_system' => false, 'status' => 'ACTIVE']);
        }
    }

    // -------------------------------------------------------------- used tires

    /**
     * Used Tire Management, through the same services as the application: the tires removed by
     * the truck replacement are inspected (REUSE into Semarang's used tire quantity, HOLD); a bus
     * Replacement then issues that REUSE tire through a USED Part Request line (its "DRIVE /
     * TRAILER" usage restriction raises a warning) next to a new-stock line; of the bus tires it
     * removes, one goes back to used stock (REUSE), one is scrapped and one awaits inspection.
     */
    private function usedTires(array $vehicles, Product $truckTire): void
    {
        if (TireUsedInspection::query()->where('tenant_id', $this->tenant->id)->exists()) {
            return;
        }
        $semarang = Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', 'ALPHA-SMG-WH1')->firstOrFail();
        $removed = fn (Vehicle $vehicle, string $code) => Tire::query()->where('tenant_id', $this->tenant->id)
            ->where('serial_number', DemoSerial::make("{$vehicle->registration_number}|{$code}"))->firstOrFail();
        $inspect = fn (Tire $tire, array $answers, ?string $warehouseId): Tire => $this->inspectUsedTire($tire, $answers, $warehouseId);

        // Truck replacement (1FL1 / 1FR1 of the tandem truck) left two REMOVED tires.
        $truck = $vehicles['truck2']->fresh();
        $reuse = $inspect($removed($truck, '1FL1'), [], $semarang->id);
        $inspect($removed($truck, '1FR1'), ['internal_inspected' => 'NOT_YET'], null); // HOLD: interior not inspected yet

        // Bus Replacement: the REUSE tire (USED line, from the used tire quantity) and three new-stock
        // tires (NEW line), all issued from Semarang. The new tires are consumed (installed); the
        // REUSE tire is issued and waits to be consumed, so its usage-restriction warning shows.
        $operations = app(TireOperationService::class);
        $workOrders = app(WorkOrderService::class);
        $bus = $vehicles['bus']->fresh();
        $positions = collect($operations->context($bus)['positions'])->pluck('position_code')->slice(1, 4)->values();
        $newStock = collect($operations->replacementCandidates($this->tenant->id, $truckTire->id))->where('source', 'NEW_STOCK')->pluck('id')->values();
        $op = $operations->create($bus, [
            'operation_type' => 'REPLACEMENT', 'operated_date' => now()->subDay()->toDateString(), 'operated_time' => '14:30', 'odometer' => '152900',
            'items' => $positions->map(fn ($code, $i) => ['position_code' => $code, 'replacement_tire_id' => $i === 0 ? $reuse->id : $newStock[$i - 1]])->all(),
        ], $this->admin->id);
        $busWorkOrder = $workOrders->assign($workOrders->approve($workOrders->submit(WorkOrder::query()->findOrFail($op->work_order_id))));
        DemoWorkspaceAssignment::approve($busWorkOrder, $this->admin->id);
        $workOrders->start($workOrders->schedule($busWorkOrder));
        $requests = app(WorkOrderPartRequestService::class);
        $request = $requests->approve(WorkOrderPartRequest::query()->where('tire_operation_id', $op->id)->firstOrFail(), null, $this->admin->id, 'Approved: one reused and three new tires');
        $request = $requests->issue($request, $semarang, $this->admin->id);
        app(WorkOrderPartService::class)->consume($request->items->firstWhere('stock_condition', 'NEW')->plannedPart, null, $this->admin->id);

        // The bus tires taken off: one back to used stock, one scrapped (cord exposed), one awaiting inspection.
        $inspect($removed($bus, $positions[1]), [], $semarang->id);
        $inspect($removed($bus, $positions[2]), ['cord_exposure' => 'PRESENT'], null);
    }

    /**
     * Used Tire Management → Retread / Scrap demo. The retread-program truck (H 3203 ALP) has six
     * tires replaced with new casings: four removed with disposition Retread, two removed and then
     * scrapped through their inspection (cord exposed). The Retread cycles cover every state —
     * waiting, in process (Open Cycle: vendor, estimated price, photos, notes), received (pending
     * inspection) and completed (inspection approved, back to used stock) — and the scrapped tires
     * can be sold from the Scrap tab (Recently Scrapped → Sell Sparepart).
     */
    private function retreadAndScrap(array $vehicles, Product $truckTire): void
    {
        $serial = fn (int $n) => DemoSerial::make("ALPHA|CASING|{$n}");
        if (Tire::query()->where('tenant_id', $this->tenant->id)->where('serial_number', $serial(1))->exists()) {
            return;
        }
        $semarang = Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', 'ALPHA-SMG-WH1')->firstOrFail();
        $vendor = Partner::query()->where('tenant_id', $this->tenant->id)->where('code', 'VND-KARYABAN')->firstOrFail();
        $truck = $vehicles['truck3']->fresh();
        $installed = Tire::query()->where('tenant_id', $this->tenant->id)->where('current_vehicle_id', $truck->id)->orderBy('current_position')->get()->values();
        $tires = app(TireService::class);
        $registrations = app(TireRegistrationService::class);

        $removed = ['RETREAD' => [], 'SCRAP' => []];
        foreach (['RETREAD', 'RETREAD', 'RETREAD', 'RETREAD', 'SCRAP', 'SCRAP'] as $i => $outcome) {
            $old = $installed[$i];
            $new = $registrations->register($this->tenant->id, ['product_id' => $truckTire->id, 'serial_number' => $serial($i + 1), 'manufacture_date_code' => '0926']);
            $tires->replace(
                $old, $new, $outcome === 'RETREAD' ? 'Tread worn to the pull point; casing sound.' : 'Sidewall cut found on the road check.',
                $outcome === 'RETREAD' ? 'RETREAD' : 'REUSE', // REUSE → REMOVED: the inspection decides (here: scrap)
                (float) $truck->current_odometer, null, $this->admin->id, now()->subDays(12 - $i),
            );
            $removed[$outcome][] = $old->fresh();
        }

        $cycles = app(TireCycleService::class);
        [, $inProcess, $received, $completed] = $removed['RETREAD']; // the first one waits for Open Cycle
        $open = fn (Tire $tire, string $price, string $notes) => $cycles->open($tire->fresh(), $vendor->id, $price, [
            DemoPhoto::make('casing-front.png', [90, 90, 90]), DemoPhoto::make('casing-tread.png', [60, 60, 60]),
        ], $notes, $this->admin->id);
        $open($inProcess, '850000.00', 'Hot retread, pattern R150.');
        $cycles->receive($open($received, '850000.00', 'Cold retread; check the bead area on return.'), $this->admin->id);
        $cycles->receive($open($completed, '875000.50', 'Hot retread, pattern R150.'), $this->admin->id);
        $this->inspectUsedTire($completed, [], $semarang->id);

        foreach ($removed['SCRAP'] as $tire) {
            $this->inspectUsedTire($tire, ['cord_exposure' => 'PRESENT'], null);
        }
    }

    /**
     * Purchase Order Return to Vendor demo: one partially received PO per return state (the
     * "Demo restock: batteries" PO above stays partially received with no return).
     */
    private function purchaseReturns(): void
    {
        $returns = app(PurchaseReturnService::class);
        foreach ([
            ['note' => 'Demo return: oil filters, refund requested.', 'product' => 'Oil Filter', 'qty' => 30, 'price' => 85000, 'vendor' => 'VND-MITRA', 'wh' => 'ALPHA-DPS-WH1', 'received' => 20, 'invoice' => 'INV-MUS-2026-0201', 'option' => PurchaseReturn::REFUND, 'return' => 3, 'decision' => null],
            ['note' => 'Demo return: brake pads, refund accepted.', 'product' => 'Brake Pad Set', 'qty' => 20, 'price' => 425000, 'vendor' => 'VND-ANDALAN', 'wh' => 'ALPHA-SMG-WH1', 'received' => 12, 'invoice' => 'INV-APN-2026-0202', 'option' => PurchaseReturn::REFUND, 'return' => 2, 'decision' => 'ACCEPT'],
            ['note' => 'Demo return: batteries, refund rejected then redelivery.', 'product' => 'Truck Battery 12V 100Ah', 'qty' => 10, 'price' => 1800000, 'vendor' => 'VND-SINAR', 'wh' => 'ALPHA-SBY-WH1', 'received' => 6, 'invoice' => 'INV-SSC-2026-0203', 'option' => PurchaseReturn::REFUND, 'return' => 1, 'decision' => 'REJECT'],
            ['note' => 'Demo return: oil filters, redelivery requested.', 'product' => 'Oil Filter', 'qty' => 24, 'price' => 85000, 'vendor' => 'VND-MITRA', 'wh' => 'ALPHA-DPS-WH1', 'received' => 18, 'invoice' => 'INV-MUS-2026-0204', 'option' => PurchaseReturn::REDELIVERY, 'return' => 2, 'decision' => null],
        ] as $chain) {
            if (PurchaseRequest::query()->where('tenant_id', $this->tenant->id)->where('notes', $chain['note'])->exists()) {
                continue;
            }
            $po = $this->purchaseChain($chain);
            if (! $po) {
                continue;
            }
            $return = $returns->create($po, $chain['option'], [
                ['purchase_order_item_id' => $po->items()->first()->id, 'quantity' => $chain['return']],
            ], 'Damaged on arrival; returned to the vendor.', $this->admin->id);
            if ($chain['decision'] === 'ACCEPT') {
                $returns->accept($return, 'Vendor credit note issued.', $this->admin->id);
            } elseif ($chain['decision'] === 'REJECT') {
                $returns->reject($return, 'Vendor declined the refund and will redeliver.', $this->admin->id);
            }
        }
    }

    /** Vehicle documents with and without expiry / extension (Vehicle Detail → Documents). */
    private function vehicleDocuments(array $vehicles): void
    {
        $documents = app(VehicleDocumentService::class);
        $date = fn (int $days) => now()->addDays($days)->toDateString();
        foreach ([
            ['car1', 'REGISTRATION', 'STNK-B3101ALP', true, $date(330), false, null],
            ['car1', 'VEHICLE_TAX', 'PKB-B3101ALP-2026', true, $date(25), true, $date(18)],
            ['truck1', 'INSURANCE', 'POL-H3201-ALP', true, $date(140), false, null],
            ['bus', 'INSPECTION_CERTIFICATE', 'KIR-DK3301-ALP', true, $date(-5), true, $date(10)],
            ['van', 'OTHER', 'BPKB-D3102-ALP', false, null, false, null],
        ] as [$key, $type, $number, $hasExpiry, $expiry, $extend, $deadline]) {
            $vehicle = $vehicles[$key];
            if (VehicleDocument::query()->where('vehicle_id', $vehicle->id)->where('document_number', $number)->exists()) {
                continue;
            }
            $documents->upload($vehicle, DemoQuotationDocument::make($number, 'Demo vehicle document', "{$number}.pdf"), [
                'document_type' => $type, 'document_number' => $number, 'issue_date' => now()->subYear()->toDateString(),
                'has_expiry' => $hasExpiry, 'expiry_date' => $expiry, 'needs_extension' => $extend, 'extension_deadline' => $deadline,
            ], $this->admin->id);
        }
    }

    /**
     * Workspace scheduling demo (Jakarta workshop): bays with capacity 1, 2 and 3 and Work Orders in
     * every scheduling state — DRAFT without a workspace, APPROVED ready to schedule, SCHEDULED with
     * and without an approved workspace (Start blocked), IN_PROGRESS, QC_PENDING moved to the QC bay
     * (transfer history), COMPLETED (assignment completed with the Work Order), an approved and
     * transferable assignment and a pending request that fills the capacity-2 bay. All through the
     * domain services; idempotent via the complaint marker.
     */
    private function workspaceScheduling(array $branches, array $vehicles): void
    {
        $marker = 'Demo workspace scheduling:';
        $workshop = $branches['ALPHA-JKT']['workshop'];
        $bays = [];
        foreach ([1, 2, 3] as $capacity) {
            $bays[$capacity] = Workspace::query()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'workshop_id' => $workshop->id, 'code' => "JKT-BAY-C{$capacity}"],
                ['name' => "Jakarta Bay (capacity {$capacity})", 'workspace_type' => 'GENERAL_SERVICE_BAY', 'capacity' => $capacity, 'capacity_unit' => 'vehicles', 'status' => 'AVAILABLE']
            );
        }
        $qcBay = Workspace::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'workshop_id' => $workshop->id, 'code' => 'JKT-QC-DEMO'],
            ['name' => 'Jakarta QC Bay (demo)', 'workspace_type' => 'QC_BAY', 'capacity' => 1, 'capacity_unit' => 'vehicles', 'status' => 'AVAILABLE']
        );
        if (WorkOrder::query()->where('tenant_id', $this->tenant->id)->where('complaint', 'like', $marker.'%')->exists()) {
            return;
        }

        $workOrders = app(WorkOrderService::class);
        $assignments = app(WorkspaceReservationService::class);
        $create = fn (string $label, Vehicle $vehicle) => $workOrders->create($vehicle->fresh(), [
            'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM', 'complaint' => "{$marker} {$label}",
        ], $this->admin->id);
        $assigned = fn (WorkOrder $wo) => $workOrders->assign($workOrders->approve($workOrders->submit($wo)));
        $approved = fn (WorkOrder $wo, Workspace $bay, $start, int $hours = 3) => $assignments->approve(
            $assignments->reserve($bay, $start, $start->copy()->addHours($hours), $wo->id, $this->admin->id), $this->admin->id,
        );
        $tomorrow = now()->addDay()->setTime(8, 0);

        // 1. DRAFT — no workspace yet.
        $create('draft, no workspace yet.', $vehicles['car1']);
        // 2. APPROVED — eligible for Schedule Workspace.
        $workOrders->approve($workOrders->submit($create('approved, ready to schedule a workspace.', $vehicles['car2'])));
        // 3. SCHEDULED with an approved workspace and work date (capacity-2 bay, tomorrow 08:00–11:00).
        $wo = $assigned($create('scheduled with an approved workspace.', $vehicles['car1']));
        $approved($wo, $bays[2], $tomorrow);
        $workOrders->schedule($wo->fresh());
        // 4. SCHEDULED without a workspace — Start is blocked until one is approved.
        $workOrders->schedule($assigned($create('scheduled without a workspace (start blocked).', $vehicles['car2'])));
        // 5. IN_PROGRESS in the capacity-3 bay.
        $wo = $assigned($create('in progress in the capacity-3 bay.', $vehicles['car1']));
        $approved($wo, $bays[3], now()->subHour(), 6);
        $workOrders->start($workOrders->schedule($wo->fresh()));
        // 6. QC_PENDING — moved from the capacity-1 bay to the QC bay (TRANSFERRED history + new approved assignment).
        $wo = $assigned($create('QC pending, moved to the QC bay.', $vehicles['car2']));
        $first = $approved($wo, $bays[1], now()->subHours(3), 2);
        $workOrders->submitToQc($workOrders->start($workOrders->schedule($wo->fresh())));
        $assignments->transfer($first, $qcBay, now()->subMinutes(30), now()->addHours(2), $this->admin->id);
        // 7. COMPLETED — its assignment completed together with the Work Order.
        $wo = $assigned($create('completed; workspace assignment completed with it.', $vehicles['car1']));
        $approved($wo, $bays[1], now()->subDays(2)->setTime(8, 0));
        $workOrders->complete($workOrders->submitToQc($workOrders->start($workOrders->schedule($wo->fresh()))));
        // 8. ASSIGNED with an approved, transferable workspace (capacity-1 bay, tomorrow 13:00–16:00).
        $approved($assigned($create('assigned with an approved, transferable workspace.', $vehicles['car2'])), $bays[1], $tomorrow->copy()->addHours(5));
        // 9. A pending request that fills the capacity-2 bay tomorrow 08:00–11:00 (2 / 2).
        $wo = $assigned($create('workspace requested, awaiting approval.', $vehicles['car1']));
        $assignments->reserve($bays[2], $tomorrow, $tomorrow->copy()->addHours(3), $wo->id, $this->admin->id);
    }

    /** A Used Tire Management inspection through the decision engine, approved by the demo admin. */
    private function inspectUsedTire(Tire $tire, array $answers, ?string $warehouseId): Tire
    {
        // The DOT code is read during the inspection (Q1 identity complete).
        $tire->update(['manufacture_date_code' => $tire->manufacture_date_code ?? '1225']);
        $points = [];
        foreach ([1, 2, 3] as $zone) {
            foreach (['INNER_MAIN', 'OUTER_MAIN'] as $groove) {
                $points[] = ['zone' => $zone, 'groove' => $groove, 'depth_mm' => $zone === 2 ? '8.5' : '9.5'];
            }
        }
        $inspection = app(UsedTireInspectionService::class)->submit($tire->fresh(), array_merge([
            'identity_status' => 'COMPLETE', 'internal_inspected' => 'YES', 'wear_pattern' => 'EVEN', 'bulge_separation' => 'NONE',
            'cord_exposure' => 'NONE', 'sidewall_condition' => 'NORMAL', 'bead_condition' => 'NORMAL', 'inner_liner_condition' => 'NORMAL',
            'run_flat_overheat' => 'NO', 'leak_foreign_object' => 'NO', 'previous_repair' => 'NONE', 'age_chemical' => 'NONE',
            'casing_compliance' => 'MEETS', 'd_new_mm' => '16', 'measurements' => $points,
        ], $answers), $this->admin);
        app(UsedTireInspectionService::class)->approve($inspection, ['warehouse_id' => $warehouseId, 'note' => 'Demo inspection'], $this->admin);

        return $tire->fresh();
    }

    /**
     * Used tire inspection rule profiles, one per tire category. DEMO VALUES ONLY — every company
     * sets its own thresholds (Tire Management → Inspection Rules); the application has no defaults.
     */
    private function tireRuleProfiles(): void
    {
        foreach ([
            ['PASSENGER_LT', 'Passenger / Light Truck (demo values)', '1.60', '3.00', 72, 60, 1, ['TREAD'], 6, null, 2, ['positions' => [], 'notes' => 'Repaired tires: not on the front axle.']],
            ['TRUCK_BUS', 'Truck / Bus (demo values)', '2.00', '4.00', 120, 84, 2, ['TREAD', 'SHOULDER'], 10, ['25', '5', '8'], 3, ['positions' => ['DRIVE', 'TRAILER'], 'max_speed_kmh' => 100, 'notes' => 'Retreaded / repaired tires: not on the steer axle.']],
            ['OTR', 'OTR / Heavy Equipment (demo values)', '5.00', '10.00', 120, 96, 2, ['TREAD', 'SHOULDER', 'SIDEWALL'], 20, ['75', '15', '25'], 4, ['operations' => ['Site haulage only'], 'notes' => 'Section-repaired tires: reduced load per site rules.']],
        ] as [$category, $name, $service, $pull, $aMax, $aRetread, $nRetread, $locations, $puncture, $cut, $maxRepairs, $application]) {
            TireRuleProfile::query()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'tire_category' => $category, 'product_id' => null, 'application' => null, 'status' => 'ACTIVE'],
                [
                    'name' => $name, 'd_service_mm' => $service, 'd_pull_mm' => $pull, 'a_max_months' => $aMax, 'a_retread_max_months' => $aRetread, 'n_retread_max' => $nRetread,
                    'repair_limits' => [
                        'allowed_locations' => $locations, 'max_puncture_diameter_mm' => $puncture,
                        'max_cut_length_mm' => $cut[0] ?? null, 'max_cut_width_mm' => $cut[1] ?? null, 'max_cut_depth_mm' => $cut[2] ?? null,
                        'max_repairs' => $maxRepairs, 'allow_overlap_previous_repair' => false, 'allow_reinforcement_damage' => $category === 'OTR',
                    ],
                    'application_limits' => $application, 'version' => 1, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
                ]
            );
        }
    }
}
