<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Services\ComponentAssetService;
use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Models\VehicleModel as VehicleModelMaster;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\WarehouseBin;
use App\Domain\Organization\Models\WarehouseRack;
use App\Domain\Organization\Models\WarehouseZone;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Procurement\Services\PurchaseRequestService;
use App\Domain\Procurement\Services\RfqService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\ToolType;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\ProductMaster\Services\ProductCreationService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TirePlyRating;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\Tire\Services\TireService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Services\WarrantyClaimService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 4 demo/dev data for ALPHA (the only tenant entitled to the supply
 * chain modules — see DemoDataSeeder): a product catalog with compatibility
 * mapping, stock balances across the two existing branch warehouses, an
 * open reservation on a live Work Order, a full PR -> RFQ -> quotation ->
 * PO -> partial receipt procurement chain, a tire installed with rotation
 * and inspection history, an installed component asset under warranty with
 * an in-flight claim. Built through the real domain services, mirroring
 * OperationsSeeder's approach, so seeded state matches what the API would
 * produce.
 */
class SupplyChainSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'ALPHA')->firstOrFail();
        $jktWarehouse = Warehouse::query()->where('tenant_id', $tenant->id)->where('code', 'ALPHA-JKT-WH1')->firstOrFail();
        $bdgWarehouse = Warehouse::query()->where('tenant_id', $tenant->id)->where('code', 'ALPHA-BDG-WH1')->firstOrFail();
        $truck = VehicleCategory::query()->where('code', 'VC-TRUCK')->whereNull('tenant_id')->firstOrFail();
        $brakeGroup = ComponentGroup::query()->where('code', 'CG-BRAKE')->whereNull('tenant_id')->firstOrFail();
        $engineGroup = ComponentGroup::query()->where('code', 'CG-ENGINE')->whereNull('tenant_id')->firstOrFail();
        $elecGroup = ComponentGroup::query()->where('code', 'CG-ELEC')->whereNull('tenant_id')->firstOrFail();
        $vehicle1 = Vehicle::query()->where('tenant_id', $tenant->id)->where('registration_number', 'B 1001 ALP')->firstOrFail();
        $vehicle2 = Vehicle::query()->where('tenant_id', $tenant->id)->where('registration_number', 'B 1002 ALP')->firstOrFail();

        // Warehouse Manager, scoped to the Bandung warehouse only (Section
        // 47's example: a WH-BDG-01-scoped user must not see/reserve/issue
        // JKT-WH1 stock).
        $warehouseManagerRole = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Warehouse Manager')->first();
        $warehouseManager = User::query()->updateOrCreate(
            ['email' => 'alpha.warehousemanager@optifleet.test'],
            ['name' => 'ALPHA Warehouse Manager (Bandung)', 'password' => Hash::make('password'), 'user_type' => 'tenant', 'status' => 'active']
        );
        TenantUser::query()->firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $warehouseManager->id], ['status' => 'active', 'joined_at' => now()]);
        if ($warehouseManagerRole) {
            RoleAssignment::query()->firstOrCreate(['user_id' => $warehouseManager->id, 'tenant_id' => $tenant->id, 'role_id' => $warehouseManagerRole->id]);
        }
        DataScopeAssignment::query()->firstOrCreate([
            'user_id' => $warehouseManager->id, 'tenant_id' => $tenant->id, 'scope_type' => 'WAREHOUSE', 'scope_resource_id' => $bdgWarehouse->id,
        ]);

        // --- Product master ---
        // Every Product goes through ProductCreationService — the exact path POST
        // /app/products uses (category/Item Type check, mandatory Component Group +
        // Category, dynamic specification, server-generated Item Code and SKU). The
        // baseline Item Type categories come from ProductReferenceDataSeeder
        // (PC-SPAREPART / PC-TIRE / PC-TOOL); this seeder never creates categories.
        // Idempotency key: tenant + product name (SKU is server-generated).
        $uomPcs = Uom::query()->whereNull('tenant_id')->where('code', 'PCS')->firstOrFail();
        $sparePartCategory = ProductCategory::query()->whereNull('tenant_id')->where('code', 'PC-SPAREPART')->firstOrFail();
        $tireCategory = ProductCategory::query()->whereNull('tenant_id')->where('code', 'PC-TIRE')->firstOrFail();
        $toolCategory = ProductCategory::query()->whereNull('tenant_id')->where('code', 'PC-TOOL')->firstOrFail();

        $bin = $this->resolveStorageBin($tenant, $jktWarehouse);
        $tireRefs = $this->resolveTireReferences($tenant);
        $toolType = ToolType::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PWR-TOOL'],
            ['name' => 'Power Tool', 'is_system' => false, 'status' => 'ACTIVE']
        );

        app(TenantContext::class)->setTenantId($tenant->id);

        // Vehicle compatibility references the Vehicle Brand / Model masters OperationsSeeder
        // created for this tenant's Hino Ranger FG fleet.
        $rangerFg = VehicleModelMaster::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', 'RANGER-FG')->firstOrFail();
        $fits = fn (string $groupId) => [[
            'vehicle_brand_id' => $rangerFg->vehicle_brand_id, 'vehicle_model_id' => $rangerFg->id, 'vehicle_category_id' => $truck->id, 'component_group_id' => $groupId,
        ]];

        $base = fn (string $categoryId, string $type) => [
            'product_category_id' => $categoryId, 'product_type' => $type, 'uom_id' => $uomPcs->id, 'default_storage_bin_id' => $bin->id,
        ];

        $brakePad = $this->makeProduct($tenant, 'Brake Pad Set (Front)', $base($sparePartCategory->id, 'SPARE_PART') + [
            'brand' => 'Akebono', 'track_serial_number' => false,
        ] + $this->classification('CG-BRAKE', 'DISC_BRAKE'), [
            'part_number' => 'BRK-PAD-FRT-01', 'part_type' => 'AFTERMARKET',
            'compatibilities' => $fits($brakeGroup->id),
        ]);
        $oilFilter = $this->makeProduct($tenant, 'Engine Oil Filter', $base($sparePartCategory->id, 'SPARE_PART') + [
            'brand' => 'Denso', 'track_serial_number' => false,
        ] + $this->classification('CG-ENGINE-LUBE', 'OIL_FILTER'), [
            'part_number' => 'OIL-FLT-01', 'part_type' => 'OEM',
            'compatibilities' => $fits($engineGroup->id),
        ]);
        $battery = $this->makeProduct($tenant, 'Truck Battery 12V 100Ah', $base($sparePartCategory->id, 'SPARE_PART') + [
            'brand' => 'GS Astra', 'track_serial_number' => true,
        ] + $this->classification('CG-ELEC', 'BATTERY'), [
            'part_number' => 'BAT-12V-100AH', 'part_type' => 'AFTERMARKET',
            'compatibilities' => $fits($elecGroup->id),
        ]);
        $tireProduct = $this->makeProduct($tenant, 'Truck Tire 295/80R22.5', $base($tireCategory->id, 'TIRE') + [
            'brand' => 'Bridgestone', 'track_serial_number' => true,
        ] + $this->classification('CG-TYRE', 'TRUCK_TIRE'), [
            'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'Highway Rib', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
            'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
            'single_load_index_id' => $tireRefs['loadIndex']->id, 'speed_rating_id' => $tireRefs['speedRating']->id,
            'dual_load_index_id' => $tireRefs['loadIndex']->id, 'ply_rating_id' => $tireRefs['plyRating']->id,
            // TRA Code/Star Rating genuinely omitted, not applicable to this tire —
            // ProductSpecificationService::validateTire() defaults both when absent.
        ]);
        $this->makeProduct($tenant, 'Impact Wrench', $base($toolCategory->id, 'TOOL') + ['track_serial_number' => true], [
            'tool_type_id' => $toolType->id, 'checkout_required' => true, 'calibration_required' => false,
            'maintenance_required' => true, 'maintenance_interval_value' => 6, 'maintenance_interval_unit' => 'MONTHS',
        ]);

        // --- Stock balances across both warehouses ---
        $inventory = app(InventoryService::class);
        if (! \App\Domain\Inventory\Models\StockMovement::query()->where('warehouse_id', $jktWarehouse->id)->where('product_id', $brakePad->id)->exists()) {
            $inventory->receive($jktWarehouse, $brakePad, 6, 250000, 'OPENING', null, null, $warehouseManager->id);
            $inventory->receive($jktWarehouse, $oilFilter, 12, 85000, 'OPENING', null, null, $warehouseManager->id);
            $inventory->receive($bdgWarehouse, $brakePad, 3, 250000, 'OPENING', null, null, $warehouseManager->id);
            $inventory->receive($bdgWarehouse, $oilFilter, 5, 85000, 'OPENING', null, null, $warehouseManager->id);
        }

        // --- A Work Order needing parts: reserve brake pads against a live WO ---
        $needsPartsComplaint = 'Brake pads worn, replacement needed.';
        if (! \App\Domain\WorkOrder\Models\WorkOrder::query()->where('tenant_id', $tenant->id)->where('complaint', $needsPartsComplaint)->exists()) {
            $workOrders = app(WorkOrderService::class);
            $jktWorkshopId = $vehicle1->default_workshop_id;
            $wo = $workOrders->create($vehicle1, [
                'workshop_id' => $jktWorkshopId, 'maintenance_type' => 'CORRECTIVE', 'complaint' => $needsPartsComplaint,
            ], $warehouseManager->id);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo);
            $wo = $workOrders->start($wo);

            // Work Order "Reserve" = a Part Request: one issued (request -> approve -> issue from
            // Jakarta), one still REQUESTED in the approval queue.
            $partRequests = app(\App\Domain\WorkOrder\Services\WorkOrderPartRequestService::class);
            $issued = $partRequests->request($wo, [['product_id' => $brakePad->id, 'quantity_requested' => 2]], null, $warehouseManager->id);
            $issued = $partRequests->approve($issued, null, $warehouseManager->id, null);
            $partRequests->issue($issued, $jktWarehouse, $warehouseManager->id);
            $partRequests->request($wo, [['product_id' => $oilFilter->id, 'quantity_requested' => 1]], 'Oil filter for the same visit.', $warehouseManager->id);
        }

        // --- Vendors ---
        $sparePartVendor = Partner::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'VND-SINAR'],
            ['name' => 'PT Sinar Suku Cadang', 'partner_type' => 'SPARE_PART_SUPPLIER', 'contact_name' => 'Hendra Wijaya', 'contact_phone' => '021-5551234', 'payment_terms' => 'NET_30', 'status' => 'ACTIVE']
        );
        // A general Supplier so every RFQ-eligible vendor type (Supplier, Spare Part Supplier,
        // Tire Supplier) exists in the demo.
        Partner::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'VND-MITRA'],
            ['name' => 'PT Mitra Umum Supply', 'partner_type' => 'SUPPLIER', 'contact_name' => 'Dewi Lestari', 'contact_phone' => '021-5559012', 'payment_terms' => 'NET_30', 'status' => 'ACTIVE']
        );
        Partner::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'VND-BANPRIMA'],
            ['name' => 'PT Ban Prima', 'partner_type' => 'TIRE_SUPPLIER', 'contact_name' => 'Rudi Hartono', 'contact_phone' => '021-5555678', 'payment_terms' => 'NET_14', 'status' => 'ACTIVE']
        );

        // --- Procurement chain: PR -> RFQ -> quotation -> PO -> partial receipt ---
        if (! \App\Domain\Procurement\Models\PurchaseOrder::query()->where('tenant_id', $tenant->id)->exists()) {
            $prService = app(PurchaseRequestService::class);
            $pr = $prService->create($jktWarehouse, [
                'source_type' => 'MANUAL', 'priority' => 'MEDIUM', 'notes' => 'Restock oil filters ahead of scheduled services.',
            ], [
                ['product_id' => $oilFilter->id, 'requested_quantity' => 50, 'estimated_unit_price' => 85000],
            ], $warehouseManager->id);
            $pr = $prService->transition($pr, 'SUBMITTED');
            $pr = $prService->transition($pr, 'UNDER_REVIEW');
            $pr = $prService->transition($pr, 'APPROVED');

            $rfqService = app(RfqService::class);
            $rfq = $rfqService->create($jktWarehouse, [], [
                ['product_id' => $oilFilter->id, 'quantity' => 50],
            ], $pr);
            $rfq = $rfqService->inviteVendors($rfq, [$sparePartVendor->id]);
            $quotation = $rfqService->submitQuotation($rfq, $sparePartVendor, [
                'lead_time_days' => 7, 'payment_terms' => 'NET_30',
            ], [
                ['product_id' => $oilFilter->id, 'quantity' => 50, 'unit_price' => 85000, 'tax_percent' => 11],
            ], DemoQuotationDocument::make('PT Sinar Suku Cadang'), $warehouseManager->id);
            $quotation = $rfqService->selectVendor($quotation);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->createFromQuotation($quotation, $jktWarehouse, ['order_date' => now()->toDateString()], $warehouseManager->id);
            $po = $poService->transition($po, 'SUBMITTED');
            $po = $poService->approve($po, $warehouseManager->id);
            $po = $poService->transition($po, 'ISSUED');

            $poItem = $po->items()->first();
            app(GoodsReceiptService::class)->post($po, $jktWarehouse, [
                ['purchase_order_item_id' => $poItem->id, 'quantity_accepted' => 30],
            ], $warehouseManager->id, 'Partial delivery — remaining 20 backordered by vendor.', [
                // Received against the vendor's invoice (30 × 85,000 + 11% tax), 30 working days.
                'mode' => 'NEW', 'vendor_invoice_number' => 'INV-SSC-2026-0001', 'vendor_invoice_date' => now()->toDateString(),
                'amount' => '2830500.00', 'terms_of_payment_days' => 30,
                'document' => DemoQuotationDocument::make('INV-SSC-2026-0001', 'Demo vendor invoice', 'INV-SSC-2026-0001.pdf'),
            ]);
        }

        // --- Tire lifecycle: spare in stock + one installed with rotation & inspection ---
        $spareTire = Tire::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'serial_number' => 'TIRE-ALPHA-SPARE-01'],
            ['product_id' => $tireProduct->id, 'manufacturer' => 'Bridgestone', 'tire_size' => '295/80R22.5', 'current_status' => 'IN_STOCK', 'current_warehouse_id' => $jktWarehouse->id]
        );
        $installedTire = Tire::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'serial_number' => 'TIRE-ALPHA-INSTALLED-01'],
            ['product_id' => $tireProduct->id, 'manufacturer' => 'Bridgestone', 'tire_size' => '295/80R22.5', 'current_status' => 'IN_STOCK']
        );
        if ($installedTire->current_status === 'IN_STOCK') {
            $tireService = app(TireService::class);
            $tireService->install($installedTire, $vehicle1, 'FRONT_LEFT', (float) $vehicle1->current_odometer, null, $warehouseManager->id);
            $tireService->rotate($installedTire->fresh(), 'REAR_LEFT', (float) $vehicle1->current_odometer + 5000, null, $warehouseManager->id);
            $tireService->inspect($installedTire->fresh(), [
                'tread_depth_mm' => 9.5, 'pressure_psi' => 110, 'condition' => 'GOOD', 'recommendation' => 'Continue in service.',
            ], $warehouseManager->id);
        }

        // --- Component asset: installed battery under warranty, with an in-flight claim ---
        $batteryAsset = ComponentAsset::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'serial_number' => 'BAT-ALPHA-01'],
            ['product_id' => $battery->id, 'component_group_id' => $elecGroup->id, 'purchase_date' => now()->subMonths(4), 'purchase_cost' => 1800000, 'current_status' => 'IN_STOCK']
        );
        if ($batteryAsset->current_status === 'IN_STOCK') {
            app(ComponentAssetService::class)->install($batteryAsset, $vehicle2, 'ENGINE_BAY', (float) $vehicle2->current_odometer, null, $warehouseManager->id);
        }

        $warranty = Warranty::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'component_asset_id' => $batteryAsset->id],
            [
                'coverage_basis' => 'COMBINATION', 'duration_months' => 12, 'duration_km' => 40000,
                'starts_at' => now()->subMonths(4), 'start_odometer' => (float) $vehicle2->current_odometer,
                'partner_id' => $sparePartVendor->id, 'status' => 'ACTIVE',
            ]
        );

        if (! \App\Domain\Warranty\Models\WarrantyClaim::query()->where('tenant_id', $tenant->id)->exists()) {
            $claimService = app(WarrantyClaimService::class);
            $claim = $claimService->create($vehicle2, [
                'warranty_id' => $warranty->id, 'partner_id' => $sparePartVendor->id, 'component_asset_id' => $batteryAsset->id,
                'failure_date' => now()->subDays(2)->toDateString(), 'failure_odometer' => (float) $vehicle2->current_odometer,
                'reason' => 'Battery not holding charge, suspected cell failure.',
            ], $warehouseManager->id);
            $claim = $claimService->transition($claim, 'SUBMITTED');
            $claimService->transition($claim, 'UNDER_REVIEW');
        }

        $this->seedReceivedStockTransfer($tenant, $jktWarehouse, $bdgWarehouse, $oilFilter, $warehouseManager);
    }

    /**
     * A Jakarta → Bandung oil-filter transfer driven through the real StockTransferService, received
     * with damaged and lost units and a discrepancy reason, so Stock Transfer detail shows a full
     * status history (each step with its own time and user) and the receipt discrepancy table.
     * Steps are spread over two days in the past; the requester / approver is the tenant admin, the
     * Bandung warehouse manager dispatches and receives. Idempotent via the transfer's notes.
     */
    private function seedReceivedStockTransfer(Tenant $tenant, Warehouse $from, Warehouse $to, Product $product, User $warehouseManager): void
    {
        $marker = 'DEMO: received with damage and loss';
        if (\App\Domain\Inventory\Models\StockTransfer::query()->where('tenant_id', $tenant->id)->where('notes', $marker)->exists()) {
            return;
        }
        $admin = User::query()->where('email', 'alpha.admin@optifleet.test')->first() ?? $warehouseManager;
        $transfers = app(\App\Domain\Inventory\Services\StockTransferService::class);
        $context = app(TenantContext::class);
        $start = now()->subDays(3)->setTime(8, 30);
        $step = function (User $actor, int $minutes, callable $action) use ($context, $start) {
            Carbon::setTestNow($start->copy()->addMinutes($minutes));
            $context->setUser($actor);

            return $action();
        };

        try {
            $transfer = $step($admin, 0, fn () => $transfers->create($from, $to, [['product_id' => $product->id, 'quantity' => 4]], $admin->id));
            $step($admin, 1, fn () => $transfer->update(['notes' => $marker]));
            $transfer = $step($admin, 15, fn () => $transfers->transition($transfer, 'REQUESTED'));
            $transfer = $step($admin, 90, fn () => tap($transfers->transition($transfer, 'APPROVED'))->update(['approved_by' => $admin->id]));
            $transfer = $step($warehouseManager, 240, fn () => $transfers->transition($transfer, 'PREPARED'));
            $transfer = $step($warehouseManager, 300, fn () => $transfers->dispatch($transfer, $warehouseManager->id));
            $transfer = $step($warehouseManager, 310, fn () => $transfers->transition($transfer, 'IN_TRANSIT'));
            $item = $transfer->items()->firstOrFail();
            $step($warehouseManager, 1560, fn () => $transfers->receive($transfer, [[
                'item_id' => $item->id, 'quantity_received' => 2, 'quantity_damaged' => 1, 'quantity_lost' => 1,
                'discrepancy_reason' => 'One filter crushed in transit, one missing from the carton.',
            ]], $warehouseManager->id));
        } finally {
            Carbon::setTestNow();
            $context->setUser(null);
        }
    }

    /**
     * ALPHA has no Warehouse -> Zone -> Rack -> Bin hierarchy anywhere yet
     * (DemoDataSeeder only creates the Warehouse row itself) — Default
     * Storage Location is mandatory for every newly created Product, so a
     * minimal, deterministic chain is required before any Product below
     * can be created through the real validation flow.
     */
    private function resolveStorageBin(Tenant $tenant, Warehouse $warehouse): WarehouseBin
    {
        $zone = WarehouseZone::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'GENERAL'],
            ['name' => 'General Zone', 'status' => 'ACTIVE']
        );
        $rack = WarehouseRack::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_zone_id' => $zone->id, 'code' => 'GENERAL'],
            ['name' => 'General Rack', 'status' => 'ACTIVE']
        );

        return WarehouseBin::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_rack_id' => $rack->id, 'code' => 'GENERAL'],
            ['name' => 'General Bin', 'status' => 'ACTIVE']
        );
    }

    /**
     * No global (tenant_id=null) Tire reference data is seeded anywhere in
     * this codebase — the Tire spec's FKs need real rows to point at.
     * Values reflect a realistic 295/80R22.5 highway truck tire spec
     * rather than placeholders.
     *
     * @return array{loadIndex: TireLoadIndex, speedRating: TireSpeedRating, plyRating: TirePlyRating}
     */
    private function resolveTireReferences(Tenant $tenant): array
    {
        $loadIndex = TireLoadIndex::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'LI-146'],
            ['max_load_single_kg' => 3000, 'max_load_dual_kg' => 2725, 'is_system' => false, 'status' => 'ACTIVE']
        );
        $speedRating = TireSpeedRating::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'L'],
            ['max_speed_kmh' => 120, 'is_system' => false, 'status' => 'ACTIVE']
        );
        $plyRating = TirePlyRating::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => '16PR'],
            ['load_range' => 'G', 'is_system' => false, 'status' => 'ACTIVE']
        );

        return ['loadIndex' => $loadIndex, 'speedRating' => $speedRating, 'plyRating' => $plyRating];
    }

    /**
     * Replicates ProductController::store()'s own body: validate the
     * conditional spec fields BEFORE any numbering sequence is touched,
     * then generate the Item Code and persist the CTI spec row inside one
     * transaction. Looks the Product up by `sku` first so a rerun never
     * consumes a fresh Item Code or duplicates the spec/compatibility rows.
     */
    /**
     * Idempotent on tenant + name; otherwise exactly the API creation path.
     *
     * @param  array<string, mixed>  $general  StoreProductRequest fields (without name)
     * @param  array<string, mixed>  $spec  dynamic specification for the Item Type
     */
    private function makeProduct(Tenant $tenant, string $name, array $general, array $spec): Product
    {
        $existing = Product::query()->where('tenant_id', $tenant->id)->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        return app(ProductCreationService::class)->createFromInput($tenant->id, ['name' => $name, 'spec' => $spec] + $general);
    }

    /** Baseline taxonomy (ComponentTaxonomySeeder) Component Group + Category, resolved by stable code. */
    private function classification(string $groupCode, string $categoryCode): array
    {
        $group = ComponentGroup::query()->whereNull('tenant_id')->where('code', $groupCode)->firstOrFail();
        $category = ComponentCategory::query()->whereNull('tenant_id')->where('component_group_id', $group->id)->where('code', $categoryCode)->firstOrFail();

        return ['component_group_id' => $group->id, 'component_category_id' => $category->id];
    }
}
