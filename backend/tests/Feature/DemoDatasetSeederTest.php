<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireOperation;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Tire\Models\UsedTireStock;
use App\Domain\Tire\Services\TireOperationService;
use App\Domain\Tire\Support\TireOperationStatus;
use App\Domain\WorkOrder\Models\WorkOrderPartRequestItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDatasetSeeder;
use Database\Seeders\DemoSerial;
use Database\Seeders\DevDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The local demo dataset: one command seeds every README account, every ALPHA demo list reaches
 * 5 records, tire serials follow the 20-character format, Tire Operations cover every status, and
 * a re-run duplicates nothing.
 */
class DemoDatasetSeederTest extends TestCase
{
    /** Lists DemoDatasetSeeder guarantees at >= 5 records for ALPHA (exceptions are documented in the README). */
    private const LISTS = [
        'branches', 'workshops', 'warehouses', 'partners', 'vehicles', 'vehicle_brands', 'vehicle_models',
        'wheel_configuration_masters', 'vehicle_wheel_configuration_mappings', 'tires', 'tire_operations',
        'work_orders', 'purchase_requests', 'rfqs', 'vendor_quotations', 'purchase_orders', 'goods_receipts',
        'workers', 'workspaces', 'inspection_templates', 'inspections', 'maintenance_packages',
        'vehicle_maintenance_profiles', 'maintenance_schedules', 'maintenance_requests', 'breakdowns',
        'tire_load_indices', 'tire_speed_ratings', 'tire_ply_ratings', 'products', 'roles',
    ];

    private function counts(string $tenantId): array
    {
        return collect(self::LISTS)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('tenant_id', $tenantId)->count()])->all();
    }

    public function test_demo_seed_fills_every_list_with_valid_records_and_is_idempotent(): void
    {
        $this->seed(DevDemoSeeder::class);
        $alpha = Tenant::query()->where('code', 'ALPHA')->firstOrFail();

        $counts = $this->counts($alpha->id);
        foreach ($counts as $table => $n) {
            $this->assertGreaterThanOrEqual(5, $n, "ALPHA {$table} has only {$n} records");
        }

        foreach ([
            'admin@optifleet.test' => 'Platform Superadmin', 'alpha.admin@optifleet.test' => 'Tenant Admin',
            'alpha.manager@optifleet.test' => 'Fleet Manager', 'alpha.workshopmanager@optifleet.test' => 'Workshop Manager',
            'alpha.warehousemanager@optifleet.test' => 'Warehouse Manager', 'alpha.procurement@optifleet.test' => 'Procurement',
            'alpha.mechanic@optifleet.test' => 'Mechanic', 'alpha.qc@optifleet.test' => 'QC Inspector',
            'alpha.branchadmin@optifleet.test' => 'Branch Admin',
        ] as $email => $role) {
            $user = User::query()->where('email', $email)->first();
            $this->assertNotNull($user, $email);
            $this->assertTrue(DB::table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')
                ->where('role_assignments.user_id', $user->id)->where('roles.name', $role)->exists(), "{$email} lacks {$role}");
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'alpha.procurement@optifleet.test', 'password' => 'password'])->assertOk();

        $this->assertSame(0, Tire::query()->whereRaw("serial_number !~ '^[A-Z0-9-]{20}$'")->count(), 'every demo tire serial is 20 characters');

        $statuses = TireOperation::query()->where('tenant_id', $alpha->id)->with('workOrder')->get()
            ->map(fn (TireOperation $op) => TireOperationStatus::derive($op->cancelled_at, $op->workOrder?->status))->unique()->sort()->values()->all();
        $this->assertSame(['CANCELLED', 'COMPLETED', 'IN_PROGRESS', 'NEW'], $statuses);

        // Used Tire Management: every used status, inspections through the decision engine, the used
        // tire quantity and a USED Part Request line whose REUSE tire carries a usage warning.
        $tireStatuses = Tire::query()->where('tenant_id', $alpha->id)->pluck('current_status')->countBy();
        foreach (['REMOVED', 'HOLD', 'REUSE', 'SCRAPPED'] as $status) {
            $this->assertGreaterThanOrEqual(1, $tireStatuses[$status] ?? 0, "no {$status} demo tire");
        }
        $this->assertSame(['HOLD', 'REUSE', 'SCRAP'], TireUsedInspection::query()->where('tenant_id', $alpha->id)->where('status', 'APPROVED')->pluck('final_disposition')->unique()->sort()->values()->all());
        // Used stock: the bus tire inspected as REUSE and the completed retread (the truck REUSE tire was issued).
        $this->assertSame(2, (int) UsedTireStock::query()->where('tenant_id', $alpha->id)->sum('quantity_on_hand'));
        $used = WorkOrderPartRequestItem::query()->where('tenant_id', $alpha->id)->where('stock_condition', 'USED')->with('plannedPart')->get();
        $this->assertCount(1, $used);
        $this->assertSame('ISSUED', $used->first()->plannedPart->status);
        $warnings = TireOperation::query()->where('tenant_id', $alpha->id)->whereNull('cancelled_at')->whereNull('applied_at')->get()
            ->flatMap(fn (TireOperation $op) => app(TireOperationService::class)->present($op)['warnings']);
        $this->assertCount(1, $warnings);

        // Retread cycles in every state (waiting = a RETREAD tire without a cycle), with photos.
        $this->assertSame(['APPROVED', 'RECEIVED', 'SENT'], DB::table('tire_retreads')->where('tenant_id', $alpha->id)->pluck('status')->sort()->values()->all());
        $this->assertSame(1, Tire::query()->where('tenant_id', $alpha->id)->where('current_status', 'RETREAD')->whereNotIn('id', DB::table('tire_retreads')->select('tire_id'))->count());
        $this->assertSame(6, DB::table('tire_cycle_photos')->where('tenant_id', $alpha->id)->count());
        // Scrapped tires to sell from the Scrap tab.
        $this->assertGreaterThanOrEqual(3, $tireStatuses['SCRAPPED']);

        // Purchase Order Return to Vendor: every return state, plus a partially received PO without one.
        $this->assertSame(['REDELIVERY_PENDING', 'REDELIVERY_RECEIVED', 'REDELIVERY_REQUESTED', 'REFUND_ACCEPTED', 'REFUND_REQUESTED'], DB::table('purchase_returns')->where('tenant_id', $alpha->id)->distinct()->pluck('status')->sort()->values()->all());
        $this->assertTrue(DB::table('purchase_orders')->where('tenant_id', $alpha->id)->where('status', 'PARTIALLY_RECEIVED')
            ->whereNotIn('id', DB::table('purchase_returns')->select('purchase_order_id'))->exists());

        // Vehicle documents with and without expiry / extension.
        $docs = DB::table('vehicle_documents')->where('tenant_id', $alpha->id)->get();
        $this->assertTrue($docs->contains(fn ($d) => $d->document_type === 'VEHICLE_TAX' && $d->needs_extension && $d->extension_deadline !== null));
        $this->assertTrue($docs->contains(fn ($d) => ! $d->has_expiry && $d->expiry_date === null));

        // Workspace scheduling: capacity 1 / 2 / 3 bays, every assignment state incl. a transfer, a
        // SCHEDULED Work Order without a workspace, and no finished Work Order left with an open assignment.
        $this->assertEqualsCanonicalizing([1, 2, 3], DB::table('workspaces')->where('tenant_id', $alpha->id)->where('code', 'like', 'JKT-BAY-C%')->pluck('capacity')->all());
        $assignmentStatuses = DB::table('workspace_reservations')->where('tenant_id', $alpha->id)->distinct()->pluck('status')->all();
        foreach (['RESERVED', 'APPROVED', 'TRANSFERRED', 'COMPLETED'] as $status) {
            $this->assertContains($status, $assignmentStatuses, "no {$status} workspace assignment");
        }
        $withApproved = DB::table('workspace_reservations')->whereIn('status', ['APPROVED', 'ACTIVE'])->select('work_order_id');
        $this->assertTrue(DB::table('work_orders')->where('tenant_id', $alpha->id)->where('status', 'SCHEDULED')->whereNotIn('id', $withApproved)->exists());
        $this->assertTrue(DB::table('work_orders')->where('tenant_id', $alpha->id)->where('status', 'SCHEDULED')->whereIn('id', $withApproved)->exists());
        $this->assertTrue(DB::table('work_orders')->where('tenant_id', $alpha->id)->where('status', 'QC_PENDING')->whereIn('id', $withApproved)->exists());
        $this->assertFalse(DB::table('work_orders')->where('tenant_id', $alpha->id)->whereIn('status', ['COMPLETED', 'CLOSED', 'CANCELLED'])
            ->whereIn('id', DB::table('workspace_reservations')->whereIn('status', ['RESERVED', 'APPROVED', 'ACTIVE'])->select('work_order_id'))->exists(), 'a finished Work Order keeps an open workspace assignment');
        // Every Work Order that started has (or had) an approved workspace.
        $this->assertFalse(DB::table('work_orders')->where('tenant_id', $alpha->id)->whereNotNull('started_at')
            ->whereNotIn('id', DB::table('workspace_reservations')->whereNotNull('approved_at')->select('work_order_id'))->exists());

        // Maintenance History scope: every vehicle has history; the Bandung branch admin's scope holds
        // several vehicles, all of Bandung.
        $history = app(\App\Domain\History\Services\HistoryService::class);
        $vehicleCount = DB::table('vehicles')->where('tenant_id', $alpha->id)->whereNull('deleted_at')->count();
        $this->assertSame($vehicleCount, $history->query($alpha->id)->get()->pluck('vehicle_id')->unique()->count(), 'a vehicle without maintenance history');
        $bandung = DB::table('branches')->where('tenant_id', $alpha->id)->where('code', 'ALPHA-BDG')->value('id');
        $scoped = $history->query($alpha->id, [], [$bandung])->get();
        $this->assertGreaterThanOrEqual(2, $scoped->pluck('vehicle_id')->unique()->count());
        $this->assertSame([$bandung], $scoped->pluck('branch_id')->unique()->values()->all());

        // PO quantity lifecycle: the mandatory Engine Oil Filter case ends at Remaining 1, an issued PO has
        // received nothing, and no line was ever over-received.
        $lifecycle = \App\Domain\Procurement\Models\PurchaseOrder::query()->where('tenant_id', $alpha->id)
            ->whereHas('items', fn ($q) => $q->where('quantity_ordered', 24)->where('quantity_received', 18)->where('quantity_returned', 12))->firstOrFail();
        $q = collect(app(\App\Domain\Procurement\Services\PurchaseOrderQuantityService::class)->forPurchaseOrder($lifecycle))->first();
        $this->assertSame(['24.0000', '18.0000', '12.0000', '7.0000', '5.0000', '1.0000'], [
            $q['ordered_quantity'], $q['gross_received_quantity'], $q['returned_quantity'], $q['reopened_for_redelivery_quantity'], $q['accepted_refund_quantity'], $q['remaining_receivable_quantity'],
        ]);
        $this->assertTrue(DB::table('purchase_orders')->where('tenant_id', $alpha->id)->where('status', 'ISSUED')->exists(), 'an ordered, not yet received PO');
        $this->assertSame(0, DB::table('purchase_order_items')->whereRaw('quantity_received + quantity_refunded > quantity_ordered')->count());

        // Component Assets: every status represented, Goods Receipt 3 then 2 → 5 Asset# for one PO
        // line, Asset# unique, SOLD has no location, tires listed with Serial Number as Asset#.
        $statuses = DB::table('component_assets')->where('tenant_id', $alpha->id)->pluck('current_status')->unique()->sort()->values()->all();
        foreach (['IN_STOCK', 'INSTALLED', 'REMOVED', 'UNDER_REPAIR', 'RECONDITIONED', 'SCRAPPED', 'SOLD', 'RETURNED_TO_VENDOR'] as $status) {
            $this->assertContains($status, $statuses);
        }
        $this->assertTrue(DB::table('purchase_requests')->where('tenant_id', $alpha->id)->where('notes', 'Demo component assets: batteries received 3 then 2.')->exists());
        $batteryLines = DB::table('goods_receipt_items as gi')->join('component_assets as ca', 'ca.goods_receipt_item_id', '=', 'gi.id')
            ->where('ca.tenant_id', $alpha->id)->groupBy('gi.purchase_order_item_id')->selectRaw('gi.purchase_order_item_id, count(*) as n, count(distinct gi.id) as receipts')->get();
        $this->assertTrue($batteryLines->contains(fn ($l) => (int) $l->n === 5 && (int) $l->receipts === 2), 'a PO line received 3 then 2 has exactly 5 assets');
        $this->assertCount(0, DB::table('component_assets')->where('tenant_id', $alpha->id)->select('asset_number')->groupBy('asset_number')->havingRaw('count(*) > 1')->get());
        $this->assertSame(0, DB::table('component_assets')->where('tenant_id', $alpha->id)->where('current_status', 'SOLD')->where(fn ($q) => $q->whereNotNull('current_warehouse_id')->orWhereNotNull('current_vehicle_id'))->count());
        $admin = User::query()->where('email', 'alpha.admin@optifleet.test')->firstOrFail();
        $register = app(\App\Domain\ComponentAsset\Services\ComponentAssetRegisterService::class)->paginate($alpha->id, $admin, ['kind' => 'TIRE'], 100)->getCollection();
        $this->assertNotEmpty($register);
        $this->assertTrue($register->every(fn ($row) => $row['asset_number'] === $row['serial_number'] && Tire::query()->whereKey($row['id'])->exists()));

        // Retread history rows carry the previous vehicle / position and the tire's own usage.
        $cycles = collect(app(\App\Domain\Tire\Services\TireActivityService::class)->feed($alpha->id, $admin, ['RETREAD'], null, 50)->items());
        $this->assertTrue($cycles->contains(fn ($r) => $r->registration_number && $r->position && $r->usage_km !== null && $r->usage_hours !== null));
        // Tire Inspection cases: a removed tire without Manufacture Date Code, one with it, D_pull configured.
        $this->assertTrue(Tire::query()->where('tenant_id', $alpha->id)->whereIn('current_status', ['REMOVED', 'HOLD'])->whereNull('manufacture_date_code')->exists());
        $this->assertTrue(Tire::query()->where('tenant_id', $alpha->id)->whereIn('current_status', ['REMOVED', 'HOLD'])->whereNotNull('manufacture_date_code')->exists());
        $this->assertTrue(DB::table('tire_rule_profiles')->where('tenant_id', $alpha->id)->where('status', 'ACTIVE')->whereNotNull('d_pull_mm')->exists());
        $assetCounts = fn () => DB::table('component_assets')->where('tenant_id', $alpha->id)->selectRaw('current_status, count(*) as n')->groupBy('current_status')->pluck('n', 'current_status')->all();
        $assetsBefore = $assetCounts();

        $phase17Counts = fn () => collect(['purchase_returns', 'tire_retreads', 'tire_cycle_photos', 'vehicle_documents', 'workspace_reservations'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('tenant_id', $alpha->id)->count()])->all();
        $phase17 = $phase17Counts();

        $this->seed(DemoDatasetSeeder::class);
        $this->assertSame($counts, $this->counts($alpha->id), 'a re-run creates no duplicates');
        $this->assertSame(7, TireUsedInspection::query()->where('tenant_id', $alpha->id)->count(), 'a re-run inspects nothing again');
        $this->assertSame($phase17, $phase17Counts(), 'a re-run duplicates no return, cycle, photo or document');
        $this->assertSame($assetsBefore, $assetCounts(), 'a re-run generates no Component Asset again');
    }

    public function test_database_seeder_adds_demo_data_only_when_enabled(): void
    {
        config(['app.seed_demo_data' => false]);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, Tenant::query()->count());

        config(['app.seed_demo_data' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(Tenant::query()->where('code', 'ALPHA')->exists());
    }

    public function test_demo_serial_is_deterministic_20_characters(): void
    {
        $serial = DemoSerial::make('ALPHA|car1|1FL1');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{5}$/', $serial);
        $this->assertSame(20, strlen($serial));
        $this->assertSame($serial, DemoSerial::make('ALPHA|car1|1FL1'));
        $this->assertNotSame($serial, DemoSerial::make('ALPHA|car1|1FR1'));
    }
}
