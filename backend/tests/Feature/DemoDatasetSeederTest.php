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
        $this->assertSame(['REDELIVERY_PENDING', 'REDELIVERY_REQUESTED', 'REFUND_ACCEPTED', 'REFUND_REQUESTED'], DB::table('purchase_returns')->where('tenant_id', $alpha->id)->pluck('status')->sort()->values()->all());
        $this->assertTrue(DB::table('purchase_orders')->where('tenant_id', $alpha->id)->where('status', 'PARTIALLY_RECEIVED')
            ->whereNotIn('id', DB::table('purchase_returns')->select('purchase_order_id'))->exists());

        // Vehicle documents with and without expiry / extension.
        $docs = DB::table('vehicle_documents')->where('tenant_id', $alpha->id)->get();
        $this->assertTrue($docs->contains(fn ($d) => $d->document_type === 'VEHICLE_TAX' && $d->needs_extension && $d->extension_deadline !== null));
        $this->assertTrue($docs->contains(fn ($d) => ! $d->has_expiry && $d->expiry_date === null));

        $phase17Counts = fn () => collect(['purchase_returns', 'tire_retreads', 'tire_cycle_photos', 'vehicle_documents'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('tenant_id', $alpha->id)->count()])->all();
        $phase17 = $phase17Counts();

        $this->seed(DemoDatasetSeeder::class);
        $this->assertSame($counts, $this->counts($alpha->id), 'a re-run creates no duplicates');
        $this->assertSame(7, TireUsedInspection::query()->where('tenant_id', $alpha->id)->count(), 'a re-run inspects nothing again');
        $this->assertSame($phase17, $phase17Counts(), 'a re-run duplicates no return, cycle, photo or document');
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
