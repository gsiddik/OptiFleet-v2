<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Functional Testing Seeder — builds a dedicated, deterministic, [TEST]-marked
 * dataset that lets a tester exercise the latest OptiFleet business flows
 * (Dynamic Product, Workshop Scheduler, Internal/External Work Order, WAL,
 * Settlement) end to end without hand-creating prerequisite data first.
 *
 * Never runs in production (see guard below). Safe to run repeatedly:
 * every sub-seeder keys its records on a stable business identifier
 * (tenant code, user email, product sku, vehicle registration_number, a
 * `[FT-...]` scenario prefix on Work Order complaint) and looks the record
 * up before creating it, so a rerun updates in place instead of duplicating
 * — including refreshing Work Order scheduling dates to stay relative to
 * "today" (Section 40).
 *
 * Runnable standalone against an already-migrated database:
 *   php artisan db:seed --class=Database\\Seeders\\FunctionalTestingSeeder
 * It seeds its own platform prerequisites (permissions, modules, numbering
 * configurations, the Work Order workflow graph) idempotently, then its own
 * dedicated tenant — it does not depend on DemoDataSeeder/CommercialSeeder/
 * OperationsSeeder/SupplyChainSeeder and does not touch their data.
 */
class FunctionalTestingSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error(
                'FunctionalTestingSeeder refused to run: this seeder creates named test '.
                'accounts with well-known passwords and is not safe for a production database. '.
                'Allowed environments: local, development, testing.'
            );

            return;
        }

        $referenceDate = now();

        // Platform prerequisites this seeder's domain-service calls depend on
        // (permissions, modules, numbering configs, the dynamic Work Order
        // workflow graph incl. EXTERNAL transitions). Every one of these is
        // already idempotent (updateOrCreate / firstOrCreate / "already
        // published, skip"), so calling them here is safe whether or not
        // DatabaseSeeder already ran them.
        $this->call([
            PermissionSeeder::class,
            ModuleSeeder::class,
            ConfigurationDefaultsSeeder::class,
            AddExternalWorkOrderPrintSectionSeeder::class,
            AddLocalizedDocumentTemplatesSeeder::class,
            WorkflowDefaultsSeeder::class,
            RetireMaintenanceRequestNeedInformationSeeder::class,
            AddWorkOrderExternalStatusSeeder::class,
            CorrectWorkOrderExternalTransitionsSeeder::class,
            AddWorkOrderExternalClosedTransitionSeeder::class,
            NotificationDefaultsSeeder::class,
            MasterDataSeeder::class,
        ]);

        $tenant = (new FunctionalTestTenantSeeder)->run();
        (new FunctionalTestUserSeeder)->run($tenant);
        $masterData = (new FunctionalTestMasterDataSeeder)->run($tenant);
        $ops = (new FunctionalTestVehicleWorkshopSeeder)->run($tenant, $masterData);
        $products = (new FunctionalTestProductSeeder)->run($tenant, $masterData);
        (new FunctionalTestInventorySeeder)->run($tenant, $ops, $products);
        (new FunctionalTestWorkOrderSeeder)->run($tenant, $ops, $products, $referenceDate);
        (new FunctionalTestExternalWorkOrderSeeder)->run($tenant, $ops, $referenceDate);

        $this->printSummary($tenant);
    }

    private function printSummary(Tenant $tenant): void
    {
        $this->command?->info('');
        $this->command?->info('=== Functional Testing Data Seeded ===');
        $this->command?->info('Tenant: '.$tenant->name.' (code: '.$tenant->code.')');
        $this->command?->info('');
        $this->command?->info('Test accounts (password: "password" for all):');
        foreach (FunctionalTestUserSeeder::ACCOUNTS as $role => $email) {
            $this->command?->info(sprintf('  %-18s %s', $role, $email));
        }
        $this->command?->info('');
        $this->command?->info('See docs/testing/FUNCTIONAL_TESTING_SEEDER.md for the full scenario catalog.');
    }
}
