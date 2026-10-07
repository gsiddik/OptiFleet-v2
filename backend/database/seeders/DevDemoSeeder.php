<?php

namespace Database\Seeders;

use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Deployment-readiness audit: the explicit, opt-in development/demo path.
 * Never run by `php artisan db:seed` alone (see DatabaseSeeder, which is
 * production-safe bootstrap only) — run explicitly with:
 *
 *   php artisan db:seed --class=DevDemoSeeder
 *
 * Calls BootstrapSeeder first so this is safe to run standalone against a
 * completely empty database (all required bootstrap/reference data exists
 * before any demo tenant is built on top of it), then layers the full
 * demo environment: a hardcoded local-dev superadmin login, two demo
 * tenants with branches/workshops/warehouses/roles/users (DemoDataSeeder),
 * four demo commercial subscription scenarios (CommercialSeeder — its
 * bundle/pricing catalog seeding is redundant with CommercialCatalogSeeder
 * but harmless, since both are idempotent), a full Work Order/Maintenance
 * Request/Inspection operational history (OperationsSeeder), and a
 * product/inventory/procurement/tire/warranty supply chain (SupplyChainSeeder),
 * the dataset completion with every README role login, Wheels Configurations,
 * Tire Operations and procurement chains (DemoDatasetSeeder), and the
 * functional-test tenant (FunctionalTestingSeeder).
 */
class DevDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BootstrapSeeder::class);
        self::seedDemoLayer($this);
    }

    /** The demo layer on top of the bootstrap data (also called by DatabaseSeeder on a local database). */
    public static function seedDemoLayer(Seeder $seeder): void
    {
        $seeder->call([
            DemoDataSeeder::class,
            CommercialSeeder::class,
            OperationsSeeder::class,
            SupplyChainSeeder::class,
            DemoDatasetSeeder::class,
            RimDemoSeeder::class,
            ConfigurationShowcaseSeeder::class,
            DashboardDemoSeeder::class,
        ]);
        // The demo seeders work inside a tenant context; the functional-test tenant is a different
        // tenant and must start without ALPHA's, and nothing after the seed may inherit its own.
        $context = app(TenantContext::class);
        $context->setTenantId(null);
        $seeder->call(FunctionalTestingSeeder::class);
        $context->setTenantId(null);
    }
}
