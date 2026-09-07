<?php

namespace App\Domain\ProductCatalog\Services;

use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Models\ModuleDependency;
use Illuminate\Support\Collection;

class ModuleDependencyService
{
    /**
     * Direct dependencies (modules this module directly requires).
     */
    public function directDependencies(Module $module): Collection
    {
        return $module->dependencies()->get();
    }

    /**
     * Direct dependents (modules that directly require this module).
     */
    public function directDependents(Module $module): Collection
    {
        return $module->dependents()->get();
    }

    /**
     * Full transitive closure of everything this module (directly or
     * indirectly) depends on.
     */
    public function transitiveDependencies(Module $module): Collection
    {
        return $this->closure($module->id, 'module_id', 'depends_on_module_id');
    }

    /**
     * Full transitive closure of everything that (directly or indirectly)
     * depends on this module.
     */
    public function transitiveDependents(Module $module): Collection
    {
        return $this->closure($module->id, 'depends_on_module_id', 'module_id');
    }

    private function closure(string $startModuleId, string $fromColumn, string $toColumn): Collection
    {
        $edges = ModuleDependency::query()->get(['module_id', 'depends_on_module_id']);

        $adjacency = [];
        foreach ($edges as $edge) {
            $adjacency[$edge->{$fromColumn}][] = $edge->{$toColumn};
        }

        $visited = [];
        $queue = $adjacency[$startModuleId] ?? [];

        while (! empty($queue)) {
            $currentId = array_shift($queue);
            if (isset($visited[$currentId])) {
                continue;
            }
            $visited[$currentId] = true;
            foreach ($adjacency[$currentId] ?? [] as $next) {
                if (! isset($visited[$next])) {
                    $queue[] = $next;
                }
            }
        }

        return Module::query()->whereIn('id', array_keys($visited))->get();
    }

    /**
     * True if adding an edge module -> dependsOn would introduce a cycle,
     * i.e. dependsOn already (transitively) depends on module.
     */
    public function wouldCreateCycle(string $moduleId, string $dependsOnModuleId): bool
    {
        if ($moduleId === $dependsOnModuleId) {
            return true;
        }

        $dependsOnModule = Module::query()->findOrFail($dependsOnModuleId);

        return $this->transitiveDependencies($dependsOnModule)->pluck('id')->contains($moduleId);
    }

    public function addDependency(string $moduleId, string $dependsOnModuleId): ModuleDependency
    {
        if ($moduleId === $dependsOnModuleId) {
            throw new ModuleDependencyException('A module cannot depend on itself.');
        }

        Module::query()->findOrFail($moduleId);

        if ($this->wouldCreateCycle($moduleId, $dependsOnModuleId)) {
            throw new ModuleDependencyException('This dependency would create a circular dependency.');
        }

        return ModuleDependency::query()->firstOrCreate([
            'module_id' => $moduleId,
            'depends_on_module_id' => $dependsOnModuleId,
        ]);
    }

    public function removeDependency(string $moduleId, string $dependsOnModuleId): void
    {
        ModuleDependency::query()
            ->where('module_id', $moduleId)
            ->where('depends_on_module_id', $dependsOnModuleId)
            ->delete();
    }
}
