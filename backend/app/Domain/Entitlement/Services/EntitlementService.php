<?php

namespace App\Domain\Entitlement\Services;

use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EntitlementService
{
    public function __construct(private readonly ModuleDependencyService $dependencies) {}

    public function tenantHasModule(string $tenantId, string $moduleCode): bool
    {
        return $this->activeModuleCodes($tenantId)->contains($moduleCode);
    }

    public function activeModuleCodes(string $tenantId): Collection
    {
        return Cache::remember("entitlements:tenant:{$tenantId}", 60, function () use ($tenantId) {
            return TenantModuleEntitlement::query()
                ->where('tenant_id', $tenantId)
                ->where('active', true)
                ->where(function ($q) {
                    $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now());
                })
                ->with('module')
                ->get()
                ->pluck('module.code');
        });
    }

    public function forgetCache(string $tenantId): void
    {
        Cache::forget("entitlements:tenant:{$tenantId}");
    }

    /**
     * Grants a module to a tenant. Rejects the grant unless every direct
     * dependency of the module is already an active entitlement for the
     * tenant, per the module dependency engine.
     */
    public function grant(string $tenantId, Module $module, array $attributes = []): TenantModuleEntitlement
    {
        $activeCodes = $this->activeModuleCodes($tenantId);
        $missing = $this->dependencies->directDependencies($module)
            ->pluck('code')
            ->diff($activeCodes);

        if ($missing->isNotEmpty()) {
            throw new EntitlementException(
                'Cannot enable module '.$module->code.': missing required module(s): '.$missing->implode(', ')
            );
        }

        $entitlement = TenantModuleEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'module_id' => $module->id],
            array_merge(['active' => true, 'source' => 'manual'], $attributes)
        );

        $this->forgetCache($tenantId);

        return $entitlement;
    }

    /**
     * Revokes a module from a tenant. Rejects the revoke if another
     * currently-active module still depends on it.
     */
    public function revoke(string $tenantId, Module $module): TenantModuleEntitlement
    {
        $activeCodes = $this->activeModuleCodes($tenantId)->reject(fn ($code) => $code === $module->code);
        $dependents = $this->dependencies->directDependents($module)->pluck('code');
        $blocking = $dependents->intersect($activeCodes);

        if ($blocking->isNotEmpty()) {
            throw new EntitlementException(
                'Cannot disable module '.$module->code.': still required by active module(s): '.$blocking->implode(', ')
            );
        }

        $entitlement = TenantModuleEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'module_id' => $module->id],
            ['active' => false]
        );

        $this->forgetCache($tenantId);

        return $entitlement;
    }
}
