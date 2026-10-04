<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe bootstrap: global system/reference data only — idempotent, never tenant- or
 * user-specific, no demo/random/destructive data. Shared by DatabaseSeeder (every environment)
 * and DevDemoSeeder (before its demo layer).
 */
class BootstrapSeeder extends Seeder
{
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
