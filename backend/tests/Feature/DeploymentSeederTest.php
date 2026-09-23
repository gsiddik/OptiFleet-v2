<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\ToolType;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TirePlyRating;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Models\User;
use Database\Seeders\CommercialCatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevDemoSeeder;
use Database\Seeders\ProductReferenceDataSeeder;
use Tests\TestCase;

/**
 * Deployment-readiness audit: DatabaseSeeder is now the production-safe
 * bootstrap path (`php artisan migrate && php artisan db:seed` on a fresh
 * environment). These tests prove it completes cleanly, creates no
 * tenant/user/demo data, seeds the required global reference data exactly
 * once, and stays idempotent across reruns — and that the opt-in
 * DevDemoSeeder still layers the full demo environment on top without
 * conflicting with the production bootstrap chain.
 */
class DeploymentSeederTest extends TestCase
{
    public function test_database_seeder_completes_on_fresh_database_and_creates_no_tenant_or_user_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Tenant::query()->count(), 'Production bootstrap must not create any tenant.');
        $this->assertSame(0, User::query()->count(), 'Production bootstrap must not create any user, including a hardcoded admin.');
    }

    public function test_database_seeder_creates_required_global_reference_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Permission::query()->count());
        $this->assertGreaterThan(0, Module::query()->count());

        $platformSuperadmin = Role::query()->where('tenant_id', null)->where('name', 'Platform Superadmin')->where('scope', 'platform')->first();
        $this->assertNotNull($platformSuperadmin, 'PlatformSuperadminRoleSeeder must seed the platform-level bootstrap role.');
        $this->assertGreaterThan(0, $platformSuperadmin->permissions()->count());

        $this->assertSame(6, ProductCategory::query()->whereNull('tenant_id')->count());
        $this->assertSame(5, Uom::query()->whereNull('tenant_id')->count());
        $this->assertGreaterThan(0, ToolType::query()->whereNull('tenant_id')->count());
        $this->assertGreaterThan(0, TireLoadIndex::query()->whereNull('tenant_id')->count());
        $this->assertGreaterThan(0, TireSpeedRating::query()->whereNull('tenant_id')->count());
        $this->assertGreaterThan(0, TirePlyRating::query()->whereNull('tenant_id')->count());

        $this->assertSame(5, Bundle::query()->count(), 'CommercialCatalogSeeder must seed the default sellable bundle catalog.');
    }

    public function test_database_seeder_is_idempotent_across_two_runs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $before = [
            'permissions' => Permission::query()->count(),
            'modules' => Module::query()->count(),
            'roles' => Role::query()->where('tenant_id', null)->where('scope', 'platform')->count(),
            'product_categories' => ProductCategory::query()->whereNull('tenant_id')->count(),
            'uoms' => Uom::query()->whereNull('tenant_id')->count(),
            'bundles' => Bundle::query()->count(),
        ];

        $this->seed(DatabaseSeeder::class);

        $after = [
            'permissions' => Permission::query()->count(),
            'modules' => Module::query()->count(),
            'roles' => Role::query()->where('tenant_id', null)->where('scope', 'platform')->count(),
            'product_categories' => ProductCategory::query()->whereNull('tenant_id')->count(),
            'uoms' => Uom::query()->whereNull('tenant_id')->count(),
            'bundles' => Bundle::query()->count(),
        ];

        $this->assertSame($before, $after, 'A second run of DatabaseSeeder must not duplicate any bootstrap/reference record.');
    }

    public function test_product_reference_data_seeder_is_globally_scoped_not_tenant_scoped(): void
    {
        $this->seed(ProductReferenceDataSeeder::class);

        $this->assertSame(0, TireLoadIndex::query()->whereNotNull('tenant_id')->count(), 'Tire reference data must be global, never tenant-scoped, when seeded by ProductReferenceDataSeeder.');
        $this->assertSame(0, TireSpeedRating::query()->whereNotNull('tenant_id')->count());
        $this->assertSame(0, TirePlyRating::query()->whereNotNull('tenant_id')->count());
    }

    public function test_commercial_catalog_seeder_seeds_bundles_without_any_demo_tenant(): void
    {
        $this->seed(CommercialCatalogSeeder::class);

        $this->assertSame(5, Bundle::query()->count());
        $this->assertSame(0, Tenant::query()->count(), 'CommercialCatalogSeeder must reuse only the bundle/pricing half of CommercialSeeder, never its demo-tenant subscription scenarios.');
    }

    /**
     * DevDemoSeeder is the opt-in dev/demo path (`db:seed --class=DevDemoSeeder`),
     * never run by default. It must remain runnable standalone against a
     * completely empty database: DatabaseSeeder first (so every bootstrap/
     * reference dependency the demo seeders assume already exists), then
     * the demo layer.
     */
    public function test_dev_demo_seeder_runs_standalone_against_an_empty_database(): void
    {
        $this->seed(DevDemoSeeder::class);

        $alpha = Tenant::query()->where('code', 'ALPHA')->first();
        $this->assertNotNull($alpha, 'DevDemoSeeder must produce the ALPHA demo tenant via DemoDataSeeder.');
        $this->assertGreaterThan(0, WorkOrder::query()->where('tenant_id', $alpha->id)->count(), 'OperationsSeeder must have populated demo Work Order history.');
    }
}
