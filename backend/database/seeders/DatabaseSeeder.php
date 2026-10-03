<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deployment-readiness audit: this is now the production-safe bootstrap
 * path only — every seeder here is idempotent, global (never tenant- or
 * user-specific), and contains no demo/random/destructive data. A fresh
 * production deployment runs exactly:
 *
 *   php artisan migrate --force
 *   php artisan db:seed --force
 *   php artisan storage:link
 *   php artisan platform:create-admin
 *
 * Demo/development data (demo tenants, commercial scenarios, operational
 * history, the functional-test tenant and the demo access accounts) is the
 * DevDemoSeeder layer. It is added to this default path only on a local
 * development database (APP_ENV=local) or when SEED_DEMO_DATA=true, so
 * `php artisan migrate:fresh --seed` seeds a complete dev/test database in one
 * command; production (APP_ENV=production) and the test suite never get it.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with production-safe bootstrap data
     * only. Every seeder below is idempotent (safe to run more than once)
     * and touches only global system/reference data — no tenant, no user,
     * no demo/operational record is created here.
     */
    public function run(): void
    {
        $this->call(BootstrapSeeder::class);

        // One-command dev/test seeding: `php artisan migrate:fresh --seed` on a local database also
        // layers the demo data (DevDemoSeeder's layer). Production and the automated test
        // environment stay bootstrap-only unless SEED_DEMO_DATA=true is set explicitly.
        if ((bool) (config('app.seed_demo_data') ?? app()->environment('local'))) {
            DevDemoSeeder::seedDemoLayer($this);
        }
    }
}
