<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Services\ContractService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Payment\Services\PaymentSubmissionService;
use App\Domain\Payment\Services\PaymentVerificationService;
use App\Domain\Pricing\Models\Pricing;
use App\Domain\Pricing\Services\PricingResolutionService;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\BundleService;
use App\Domain\Subscription\Services\DunningService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 2 commercial demo data (Section 55): the five default OptiFleet
 * bundles (published, with pricing), and four tenant scenarios that make
 * manual testing of the whole commercial lifecycle immediate:
 *   - ALPHA:  active, fully paid subscription
 *   - BETA:   past-due (overdue invoice, subscription PAST_DUE)
 *   - GAMMA:  pending activation (contract approved, no payment submitted)
 *   - DELTA:  suspended (grace period expired)
 */
class CommercialSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBundlesAndPricing();

        app(DunningService::class); // ensure resolvable before use below

        $this->seedActiveTenant();
        // DELTA must be evaluated to SUSPENDED before BETA's invoice goes
        // overdue — evaluateGraceAndSuspension() runs over every PAST_DUE
        // subscription globally, and would otherwise also advance BETA
        // past the PAST_DUE status this scenario is meant to demonstrate.
        $this->seedSuspendedTenant();
        $this->seedPendingTenant();
        $this->seedPastDueTenant();
    }

    /**
     * Public so CommercialCatalogSeeder (the production-safe path) can
     * reuse this exact logic without duplicating it — the bundle/pricing
     * catalog itself is real commercial reference data, not demo data,
     * but this class' other methods (below) build the ALPHA/BETA/GAMMA/
     * DELTA demo subscription scenarios and must not run in production.
     */
    public function seedBundlesAndPricing(): void
    {
        $definitions = [
            'OPTIFLEET_BASIC' => ['name' => 'OptiFleet Basic', 'price' => 3000000, 'modules' => [
                'CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORK_ORDER', 'WORKSHOP', 'HISTORY', 'REPORT',
            ]],
            'OPTIFLEET_OPERATIONS' => ['name' => 'OptiFleet Operations', 'price' => 5000000, 'modules' => [
                'CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORK_ORDER', 'WORKSHOP', 'HISTORY', 'REPORT',
                'INSPECTION', 'INVENTORY', 'PARTNER',
            ]],
            'OPTIFLEET_PROFESSIONAL' => ['name' => 'OptiFleet Professional', 'price' => 8000000, 'modules' => [
                'CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORK_ORDER', 'WORKSHOP', 'HISTORY', 'REPORT',
                'INSPECTION', 'INVENTORY', 'PARTNER', 'PROCUREMENT', 'TIRE', 'COMPONENT', 'WARRANTY',
            ]],
            'OPTIFLEET_CONNECTED' => ['name' => 'OptiFleet Connected', 'price' => 12000000, 'modules' => [
                'CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORK_ORDER', 'WORKSHOP', 'HISTORY', 'REPORT',
                'INSPECTION', 'INVENTORY', 'PARTNER', 'PROCUREMENT', 'TIRE', 'COMPONENT', 'WARRANTY', 'TELEMATICS',
            ]],
            'OPTIFLEET_INTELLIGENCE' => ['name' => 'OptiFleet Intelligence', 'price' => 18000000, 'modules' => [
                'CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORK_ORDER', 'WORKSHOP', 'HISTORY', 'REPORT',
                'INSPECTION', 'INVENTORY', 'PARTNER', 'PROCUREMENT', 'TIRE', 'COMPONENT', 'WARRANTY', 'TELEMATICS',
                // Phase 7 Section 59: MAINTENANCE_INTELLIGENCE now depends on
                // ANALYTICS (not TELEMATICS, which has no implementation) —
                // a bundle must carry every dependency of the modules it grants.
                'ANALYTICS', 'MAINTENANCE_INTELLIGENCE',
            ]],
        ];

        $bundleService = app(BundleService::class);
        $pricingResolution = app(PricingResolutionService::class);

        foreach ($definitions as $code => $def) {
            $bundle = Bundle::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $def['name'], 'description' => $def['name'].' bundle', 'status' => 'DRAFT', 'is_active' => true, 'effective_from' => now()->subYear()->toDateString()]
            );

            $moduleIds = Module::query()->whereIn('code', $def['modules'])->pluck('id')->all();
            $bundleService->syncModules($bundle, $moduleIds);

            if ($bundle->fresh()->status !== 'PUBLISHED') {
                $bundleService->publish($bundle);
            }

            $pricing = Pricing::query()->firstOrCreate(
                ['priceable_type' => 'BUNDLE', 'priceable_code' => $code, 'billing_frequency' => 'MONTHLY'],
                ['pricing_method' => 'FLAT', 'currency' => 'IDR', 'status' => 'DRAFT']
            );

            if ($pricing->versions()->where('status', 'ACTIVE')->doesntExist()) {
                $pricingResolution->publishVersion($pricing, [
                    'amount' => $def['price'],
                    'effective_from' => now()->subYear()->toDateString(),
                ]);
            }
        }
    }

    private function seedActiveTenant(): void
    {
        $tenant = Tenant::query()->where('code', 'ALPHA')->first();
        if (! $tenant || $this->hasScenario($tenant)) {
            return;
        }

        $contracts = app(ContractService::class);
        $contract = $this->createBundleContract($tenant, 'OPTIFLEET_OPERATIONS', now()->subMonths(2), now()->addYear(), true);
        $contract = $contracts->submitForApproval($contract);
        $contract = $contracts->approve($contract, $this->platformAdminId());

        $invoice = $contract->subscription->invoices()->first();
        if ($invoice) {
            $submission = app(PaymentSubmissionService::class);
            $payment = $submission->submit($invoice, [
                'payment_date' => now()->toDateString(),
                'amount' => $invoice->total,
                'payment_method' => 'BANK_TRANSFER',
                'bank_name' => 'Bank Mandiri',
                'account_name' => 'PT Alpha Fleet',
                'transaction_reference' => 'TRX-ALPHA-0001',
            ], $this->tenantAdminId($tenant));

            app(PaymentVerificationService::class)->verify($payment, $this->platformAdminId(), 'Verified against bank statement.');
        }
    }

    private function seedPastDueTenant(): void
    {
        $tenant = Tenant::query()->where('code', 'BETA')->first();
        if (! $tenant || $this->hasScenario($tenant)) {
            return;
        }

        $contracts = app(ContractService::class);
        // No payment required to activate, so the subscription reaches
        // ACTIVE immediately — mirroring a tenant who WAS active and then
        // stopped paying, which is what "past due" actually means.
        $contract = $this->createBundleContract($tenant, 'OPTIFLEET_BASIC', now()->subMonths(2), now()->addYear(), false);
        $contract = $contracts->submitForApproval($contract);
        $contract = $contracts->approve($contract, $this->platformAdminId());

        // Backdate the raised invoice so its 14-day payment term has
        // already elapsed with nothing paid — this is a seeder-only
        // shortcut to demonstrate the past-due scenario immediately rather
        // than waiting real days for the scheduler to naturally get there.
        $this->backdateInvoice($contract, now()->subDays(20));

        app(DunningService::class)->evaluateOverdueInvoices();
    }

    private function seedPendingTenant(): void
    {
        $tenant = $this->makeMinimalTenant('GAMMA', 'PT Gamma Transport');
        if ($this->hasScenario($tenant)) {
            return;
        }

        $contracts = app(ContractService::class);
        $contract = $this->createBundleContract($tenant, 'OPTIFLEET_BASIC', now(), now()->addYear(), true);
        $contract = $contracts->submitForApproval($contract);
        // Approved -> subscription created + invoice raised, but left
        // unpaid on purpose: this is the "pending activation" scenario.
        $contracts->approve($contract, $this->platformAdminId());
    }

    private function seedSuspendedTenant(): void
    {
        $tenant = $this->makeMinimalTenant('DELTA', 'PT Delta Heavy Equipment');
        if ($this->hasScenario($tenant)) {
            return;
        }

        $contracts = app(ContractService::class);
        $contract = $this->createBundleContract($tenant, 'OPTIFLEET_BASIC', now()->subMonths(3), now()->addYear(), false);
        $contract->update(['grace_period_days' => 7]);
        $contract = $contracts->submitForApproval($contract);
        $contract = $contracts->approve($contract, $this->platformAdminId());

        // Backdated well past due_date + grace_period_days so a single
        // evaluation pass reaches SUSPENDED immediately for the demo.
        $this->backdateInvoice($contract, now()->subDays(30));

        $dunning = app(DunningService::class);
        $dunning->evaluateOverdueInvoices();
        $dunning->evaluateGraceAndSuspension();
    }

    /** Idempotency: each demo tenant gets its subscription scenario (contract → invoice → payment) once. */
    private function hasScenario(Tenant $tenant): bool
    {
        return Contract::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists();
    }

    private function backdateInvoice(Contract $contract, \DateTimeInterface $dueDate): void
    {
        $invoice = $contract->fresh('subscription')->subscription->invoices()->first();
        $invoice?->update([
            'invoice_date' => \Illuminate\Support\Carbon::parse($dueDate)->subDays(14)->toDateString(),
            'due_date' => $dueDate,
        ]);
        $invoice?->billing?->update(['due_date' => $dueDate]);
    }

    private function createBundleContract(Tenant $tenant, string $bundleCode, \DateTimeInterface $start, \DateTimeInterface $end, bool $activationRequiresPayment): Contract
    {
        return app(ContractService::class)->createDraft($tenant->id, [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'billing_cycle' => 'MONTHLY',
            'payment_terms_days' => 14,
            'grace_period_days' => 7,
            'currency' => 'IDR',
            'activation_requires_payment' => $activationRequiresPayment,
            'created_by' => $this->platformAdminId(),
        ], [
            [
                'product_type' => 'BUNDLE',
                'product_reference' => $bundleCode,
                'description' => str_replace('_', ' ', $bundleCode).' subscription',
                'quantity' => 1,
                'billing_frequency' => 'MONTHLY',
                'valid_from' => $start->format('Y-m-d'),
            ],
        ]);
    }

    private function makeMinimalTenant(string $code, string $name): Tenant
    {
        $tenant = Tenant::query()->updateOrCreate(['code' => $code], ['name' => $name, 'status' => 'ACTIVE']);

        $role = Role::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Tenant Admin', 'scope' => 'tenant'],
            ['is_system' => true, 'description' => 'Tenant Admin role for '.$name]
        );
        $role->permissions()->sync(Permission::query()->where('scope', 'tenant')->pluck('id'));

        $admin = User::query()->updateOrCreate(
            ['email' => strtolower($code).'.admin@optifleet.test'],
            ['name' => $name.' Admin', 'password' => Hash::make('password'), 'user_type' => 'tenant', 'status' => 'active']
        );
        TenantUser::query()->firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $admin->id], ['status' => 'active', 'joined_at' => now()]);
        RoleAssignment::query()->firstOrCreate(['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'role_id' => $role->id]);
        DataScopeAssignment::query()->firstOrCreate(['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'scope_type' => 'TENANT', 'scope_resource_id' => null]);

        return $tenant;
    }

    private function platformAdminId(): string
    {
        return User::query()->where('email', 'admin@optifleet.test')->value('id');
    }

    private function tenantAdminId(Tenant $tenant): string
    {
        return TenantUser::query()->where('tenant_id', $tenant->id)
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('users.email', 'like', '%.admin@optifleet.test')
            ->value('tenant_users.user_id');
    }
}
