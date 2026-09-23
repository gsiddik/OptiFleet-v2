<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deployment-readiness audit: the explicit, opt-in development/demo path.
 * Never run by `php artisan db:seed` alone (see DatabaseSeeder, which is
 * production-safe bootstrap only) — run explicitly with:
 *
 *   php artisan db:seed --class=DevDemoSeeder
 *
 * Calls DatabaseSeeder first so this is safe to run standalone against a
 * completely empty database (all required bootstrap/reference data exists
 * before any demo tenant is built on top of it), then layers the full
 * demo environment: a hardcoded local-dev superadmin login, two demo
 * tenants with branches/workshops/warehouses/roles/users (DemoDataSeeder),
 * four demo commercial subscription scenarios (CommercialSeeder — its
 * bundle/pricing catalog seeding is redundant with CommercialCatalogSeeder
 * but harmless, since both are idempotent), a full Work Order/Maintenance
 * Request/Inspection operational history (OperationsSeeder), and a
 * product/inventory/procurement/tire/warranty supply chain (SupplyChainSeeder).
 */
class DevDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        $this->call([
            DemoDataSeeder::class,
            CommercialSeeder::class,
            OperationsSeeder::class,
            SupplyChainSeeder::class,
        ]);
    }
}
