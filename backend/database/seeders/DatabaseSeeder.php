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
 * Demo/development data (two fully-populated demo tenants, a commercial
 * subscription-scenario tenant set, and a Work Order/Product/Inventory
 * operational history) previously ran unconditionally as part of this
 * class — that is now exclusively DevDemoSeeder, run explicitly via
 * `php artisan db:seed --class=DevDemoSeeder`, never by this default path.
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
        $this->call([
            // --- System / bootstrap (Access Management, Modules, Configuration, Workflow, Notifications) ---
            PermissionSeeder::class,
            ModuleSeeder::class,
            PlatformSuperadminRoleSeeder::class,
            ConfigurationDefaultsSeeder::class,
            AddExternalWorkOrderPrintSectionSeeder::class,
            WorkflowDefaultsSeeder::class,
            RetireMaintenanceRequestNeedInformationSeeder::class,
            AddWorkOrderExternalStatusSeeder::class,
            CorrectWorkOrderExternalTransitionsSeeder::class,
            AddWorkOrderExternalClosedTransitionSeeder::class,
            NotificationDefaultsSeeder::class,

            // --- Reference / master data (Vehicle Category, Component Group, Product Category, UOM, Tire/Tool/Equipment reference, Storage Requirement) ---
            MasterDataSeeder::class,
            ProductReferenceDataSeeder::class,

            // --- Commercial catalog (sellable bundles + pricing — real reference data, not demo) ---
            CommercialCatalogSeeder::class,
        ]);
    }
}
