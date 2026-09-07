<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Models\ContractItem;
use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use Illuminate\Support\Collection;

/**
 * Provisions Phase 1 entitlement tables (tenant_module_entitlements,
 * tenant_capacity_limits) from a contract's items — Phase 2 does not
 * introduce a second entitlement system. Reuses the Phase 1
 * EntitlementService (and therefore its dependency enforcement); module
 * grants are topologically sorted so a module's dependencies are always
 * provisioned first within the same batch.
 */
class EntitlementProvisioningService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ModuleDependencyService $dependencies,
    ) {}

    public function provisionFromContract(Contract $contract): void
    {
        $items = $contract->items()->get();
        $moduleGrants = []; // moduleCode => ContractItem (source item)

        foreach ($items as $item) {
            if ($item->product_type === 'BUNDLE' && $item->bundleVersion) {
                foreach ($item->bundleVersion->modules as $module) {
                    $moduleGrants[$module->code] ??= $item;
                }
            } elseif (in_array($item->product_type, ['MODULE', 'ADD_ON'], true) && $item->product_reference) {
                $moduleGrants[$item->product_reference] ??= $item;
            } elseif ($item->product_type === 'CAPACITY' && $item->product_reference) {
                $this->provisionCapacity($contract, $item);
            }
        }

        foreach ($this->topologicalOrder(array_keys($moduleGrants)) as $moduleCode) {
            $module = Module::query()->where('code', $moduleCode)->first();
            if (! $module) {
                continue;
            }
            $item = $moduleGrants[$moduleCode];
            $source = $item->product_type === 'BUNDLE' ? 'BUNDLE' : ($item->product_type === 'ADD_ON' ? 'ADD_ON' : 'MODULE');

            $this->entitlements->grant($contract->tenant_id, $module, [
                'source' => $source,
                'contract_id' => $contract->id,
                'contract_item_id' => $item->id,
                'valid_from' => $item->valid_from,
                'valid_until' => $item->valid_until,
            ]);
        }
    }

    private function provisionCapacity(Contract $contract, ContractItem $item): void
    {
        TenantCapacityLimit::query()->updateOrCreate(
            ['tenant_id' => $contract->tenant_id, 'resource_type' => $item->product_reference],
            [
                'max_count' => (int) $item->quantity,
                'contract_id' => $contract->id,
                'contract_item_id' => $item->id,
            ]
        );
    }

    /**
     * Kahn's algorithm over the direct-dependency edges of just the
     * modules being granted in this batch, so e.g. VEHICLE is granted
     * before WORK_ORDER when both appear on the same contract.
     */
    private function topologicalOrder(array $moduleCodes): Collection
    {
        $modules = Module::query()->whereIn('code', $moduleCodes)->get()->keyBy('code');
        $inDegree = array_fill_keys($moduleCodes, 0);
        $edges = []; // dependsOnCode => [dependentCode, ...]

        foreach ($modules as $code => $module) {
            foreach ($this->dependencies->directDependencies($module) as $dependsOn) {
                if (! isset($modules[$dependsOn->code])) {
                    continue; // dependency not part of this batch — assumed already active
                }
                $edges[$dependsOn->code][] = $code;
                $inDegree[$code]++;
            }
        }

        $queue = collect($inDegree)->filter(fn ($d) => $d === 0)->keys()->all();
        $ordered = [];

        while (! empty($queue)) {
            $code = array_shift($queue);
            $ordered[] = $code;
            foreach ($edges[$code] ?? [] as $dependent) {
                if (--$inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Any codes not resolved (shouldn't happen — the module graph is
        // acyclic) are appended so provisioning never silently drops one.
        return collect($ordered)->merge(array_diff($moduleCodes, $ordered))->unique();
    }
}
