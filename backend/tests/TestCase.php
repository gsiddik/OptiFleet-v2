<?php

namespace Tests;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
        $this->seed(\Database\Seeders\ConfigurationDefaultsSeeder::class);
        $this->seed(\Database\Seeders\WorkflowDefaultsSeeder::class);
    }

    protected function makeTenant(array $attributes = []): Tenant
    {
        return Tenant::query()->create(array_merge([
            'code' => 'T-'.Str::upper(Str::random(6)),
            'name' => 'Test Tenant',
            'status' => 'ACTIVE',
        ], $attributes));
    }

    protected function makePlatformUser(array $permissionNames = []): array
    {
        $user = User::query()->create([
            'name' => 'Platform User',
            'email' => Str::random(8).'@optifleet.test',
            'password' => 'password',
            'user_type' => 'platform',
            'status' => 'active',
        ]);

        if (! empty($permissionNames)) {
            $role = Role::query()->create([
                'tenant_id' => null,
                'name' => 'Test Platform Role '.Str::random(4),
                'scope' => 'platform',
                'is_system' => false,
            ]);
            $role->permissions()->sync(
                Permission::query()->where('scope', 'platform')->whereIn('name', $permissionNames)->pluck('id')
            );
            RoleAssignment::query()->create(['user_id' => $user->id, 'tenant_id' => null, 'role_id' => $role->id]);
        }

        $token = $user->createToken('test', ['platform'])->plainTextToken;

        return [$user, $token];
    }

    /**
     * @param  array<string,string|null>|null  $dataScopes  scope_type => scope_resource_id
     */
    protected function makeTenantUser(Tenant $tenant, array $permissionNames = [], ?array $dataScopes = null): array
    {
        $user = User::query()->create([
            'name' => 'Tenant User',
            'email' => Str::random(8).'@optifleet.test',
            'password' => 'password',
            'user_type' => 'tenant',
            'status' => 'active',
        ]);

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        if (! empty($permissionNames)) {
            $role = Role::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Test Tenant Role '.Str::random(4),
                'scope' => 'tenant',
                'is_system' => false,
            ]);
            $role->permissions()->sync(
                Permission::query()->where('scope', 'tenant')->whereIn('name', $permissionNames)->pluck('id')
            );
            RoleAssignment::query()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'role_id' => $role->id]);
        }

        if ($dataScopes === null) {
            $dataScopes = ['TENANT' => null];
        }
        foreach ($dataScopes as $scopeType => $resourceId) {
            \App\Domain\AccessControl\Models\DataScopeAssignment::query()->create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'scope_type' => $scopeType,
                'scope_resource_id' => $resourceId,
            ]);
        }

        $token = $user->createToken('test', ['tenant:'.$tenant->id])->plainTextToken;

        return [$user, $token];
    }

    protected function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * Laravel's auth guard memoizes the resolved user for the lifetime of
     * the guard instance. Since feature tests share one Application across
     * multiple simulated requests in a single test method, switching Bearer
     * tokens between calls (e.g. tenant A then tenant B) would otherwise
     * silently reuse the first request's resolved user. Force a fresh guard
     * resolution before every JSON request.
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->app->forgetInstance('auth');
        $this->app['auth']->forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }

    protected function grantModule(Tenant $tenant, string $moduleCode): void
    {
        $module = \App\Domain\ProductCatalog\Models\Module::query()->where('code', $moduleCode)->firstOrFail();
        \App\Domain\Entitlement\Models\TenantModuleEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $module->id],
            ['active' => true, 'source' => 'test']
        );
    }

    // --- Phase 2 helpers ---

    protected function makeBundle(string $code, array $moduleCodes, bool $publish = true): \App\Domain\ProductCatalog\Models\Bundle
    {
        $bundle = \App\Domain\ProductCatalog\Models\Bundle::query()->create([
            'code' => $code,
            'name' => $code,
            'status' => 'DRAFT',
            'is_active' => true,
        ]);

        $moduleIds = \App\Domain\ProductCatalog\Models\Module::query()->whereIn('code', $moduleCodes)->pluck('id')->all();
        app(\App\Domain\ProductCatalog\Services\BundleService::class)->syncModules($bundle, $moduleIds);

        if ($publish) {
            app(\App\Domain\ProductCatalog\Services\BundleService::class)->publish($bundle);
        }

        return $bundle->fresh();
    }

    protected function makePricing(string $priceableType, string $priceableCode, string $amount, string $frequency = 'MONTHLY'): \App\Domain\Pricing\Models\Pricing
    {
        $pricing = \App\Domain\Pricing\Models\Pricing::query()->create([
            'priceable_type' => $priceableType,
            'priceable_code' => $priceableCode,
            'pricing_method' => 'FLAT',
            'billing_frequency' => $frequency,
            'currency' => 'IDR',
            'status' => 'DRAFT',
        ]);

        app(\App\Domain\Pricing\Services\PricingResolutionService::class)->publishVersion($pricing, [
            'amount' => $amount,
            'effective_from' => now()->subYear()->toDateString(),
        ]);

        return $pricing->fresh();
    }

    /**
     * Creates a DRAFT contract for a bundle item via the real ContractService
     * (so pricing resolution/proration logic is exercised the same way the
     * API would trigger it), without going through HTTP.
     */
    protected function makeContractDraft(Tenant $tenant, string $bundleCode, array $overrides = []): \App\Domain\Contract\Models\Contract
    {
        return app(\App\Domain\Contract\Services\ContractService::class)->createDraft($tenant->id, array_merge([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'billing_cycle' => 'MONTHLY',
            'payment_terms_days' => 14,
            'grace_period_days' => 7,
            'currency' => 'IDR',
            'activation_requires_payment' => false,
        ], $overrides), [
            [
                'product_type' => 'BUNDLE',
                'product_reference' => $bundleCode,
                'description' => "{$bundleCode} subscription",
                'quantity' => 1,
                'billing_frequency' => 'MONTHLY',
                'valid_from' => $overrides['start_date'] ?? now()->toDateString(),
            ],
        ]);
    }

    protected function approveContract(\App\Domain\Contract\Models\Contract $contract): \App\Domain\Contract\Models\Contract
    {
        $contracts = app(\App\Domain\Contract\Services\ContractService::class);
        $contract = $contracts->submitForApproval($contract);
        [$approver] = $this->makePlatformUser(['contract.approve']);

        return $contracts->approve($contract, $approver->id);
    }

    // --- Phase 3 helpers ---

    protected function makeBranch(Tenant $tenant, array $overrides = []): \App\Domain\Organization\Models\Branch
    {
        return \App\Domain\Organization\Models\Branch::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'code' => 'BR-'.Str::upper(Str::random(6)),
            'name' => 'Test Branch',
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeWorkshop(Tenant $tenant, \App\Domain\Organization\Models\Branch $branch, array $overrides = []): \App\Domain\Organization\Models\Workshop
    {
        return \App\Domain\Organization\Models\Workshop::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'code' => 'WS-'.Str::upper(Str::random(6)),
            'name' => 'Test Workshop',
            'workshop_type' => 'INTERNAL',
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeVehicleCategory(array $overrides = []): \App\Domain\MasterData\Models\VehicleCategory
    {
        return \App\Domain\MasterData\Models\VehicleCategory::query()->create(array_merge([
            'tenant_id' => null,
            'code' => 'VC-'.Str::upper(Str::random(6)),
            'name' => 'Test Category',
            'is_system' => true,
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeComponentGroup(array $overrides = []): \App\Domain\MasterData\Models\ComponentGroup
    {
        return \App\Domain\MasterData\Models\ComponentGroup::query()->create(array_merge([
            'tenant_id' => null,
            'code' => 'CG-'.Str::upper(Str::random(6)),
            'name' => 'Test Component',
            'is_system' => true,
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeVehicle(Tenant $tenant, \App\Domain\Organization\Models\Branch $branch, \App\Domain\MasterData\Models\VehicleCategory $category, array $overrides = []): \App\Domain\Vehicle\Models\Vehicle
    {
        return \App\Domain\Vehicle\Models\Vehicle::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'vehicle_category_id' => $category->id,
            'brand' => 'TestBrand',
            'model' => 'TestModel',
            'registration_number' => 'REG-'.Str::upper(Str::random(6)),
            'current_odometer' => 10000,
            'status' => 'ACTIVE',
            'operational_status' => 'AVAILABLE',
        ], $overrides));
    }

    protected function makeWorker(Tenant $tenant, \App\Domain\Organization\Models\Branch $branch, ?\App\Domain\Organization\Models\Workshop $workshop = null, array $overrides = []): \App\Domain\Workshop\Models\Worker
    {
        return \App\Domain\Workshop\Models\Worker::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-'.Str::upper(Str::random(6)),
            'name' => 'Test Worker',
            'branch_id' => $branch->id,
            'workshop_id' => $workshop?->id,
            'worker_type' => 'MECHANIC',
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeWarehouse(Tenant $tenant, ?\App\Domain\Organization\Models\Branch $branch = null, ?\App\Domain\Organization\Models\Workshop $workshop = null, array $overrides = []): \App\Domain\Organization\Models\Warehouse
    {
        return \App\Domain\Organization\Models\Warehouse::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'workshop_id' => $workshop?->id,
            'code' => 'WH-'.Str::upper(Str::random(6)),
            'name' => 'Test Warehouse',
            'warehouse_type' => 'BRANCH',
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeProductCategory(array $overrides = []): \App\Domain\ProductMaster\Models\ProductCategory
    {
        return \App\Domain\ProductMaster\Models\ProductCategory::query()->create(array_merge([
            'tenant_id' => null,
            'code' => 'PC-'.Str::upper(Str::random(6)),
            'name' => 'Test Product Category',
            'is_system' => true,
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeUom(array $overrides = []): \App\Domain\ProductMaster\Models\Uom
    {
        return \App\Domain\ProductMaster\Models\Uom::query()->create(array_merge([
            'tenant_id' => null,
            'code' => 'U-'.Str::upper(Str::random(4)),
            'name' => 'Piece',
            'is_system' => true,
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makeProduct(Tenant $tenant, ?\App\Domain\ProductMaster\Models\ProductCategory $category = null, ?\App\Domain\ProductMaster\Models\Uom $uom = null, array $overrides = []): \App\Domain\ProductMaster\Models\Product
    {
        $category ??= $this->makeProductCategory();
        $uom ??= $this->makeUom();

        return \App\Domain\ProductMaster\Models\Product::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'code' => 'PRD-'.Str::upper(Str::random(6)),
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'name' => 'Test Product',
            'product_category_id' => $category->id,
            'product_type' => 'SPARE_PART',
            'uom_id' => $uom->id,
            'status' => 'ACTIVE',
        ], $overrides));
    }

    protected function makePartner(Tenant $tenant, array $overrides = []): \App\Domain\Partner\Models\Partner
    {
        return \App\Domain\Partner\Models\Partner::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'code' => 'VND-'.Str::upper(Str::random(6)),
            'name' => 'Test Vendor',
            'partner_type' => 'SPARE_PART_SUPPLIER',
            'status' => 'ACTIVE',
        ], $overrides));
    }
}
