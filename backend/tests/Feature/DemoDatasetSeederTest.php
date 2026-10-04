<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireOperation;
use App\Domain\Tire\Support\TireOperationStatus;
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

        $this->seed(DemoDatasetSeeder::class);
        $this->assertSame($counts, $this->counts($alpha->id), 'a re-run creates no duplicates');
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
