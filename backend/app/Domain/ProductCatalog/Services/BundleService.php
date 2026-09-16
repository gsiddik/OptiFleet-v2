<?php

namespace App\Domain\ProductCatalog\Services;

use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Models\BundleVersion;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bundle composition and publication. Reuses the Phase 1
 * ModuleDependencyService for dependency resolution — a bundle is only a
 * named selection of existing catalog modules, so the module graph's own
 * cycle protection (enforced when a dependency edge is created, see
 * ModuleDependencyService::addDependency) already covers bundles; no
 * separate cycle detection is needed here.
 */
class BundleService
{
    public function __construct(private readonly ModuleDependencyService $dependencies) {}

    /**
     * Syncs the bundle's module composition, auto-adding every direct and
     * transitive dependency of the requested modules (Section 7.3) — a
     * module the caller tried to leave out is silently kept if another
     * still-selected module still needs it, since the closure below is
     * recomputed from the final requested set every time, not diffed
     * against the previous composition.
     *
     * Returns the modules that were added automatically beyond what was
     * requested, each with the codes of the requested module(s) that
     * pulled it in, so the frontend can explain why it's checked.
     */
    public function syncModules(Bundle $bundle, array $moduleIds): Collection
    {
        if ($bundle->status !== 'DRAFT') {
            throw new BundleException('Only a DRAFT bundle can have its module composition edited. Amend by creating a new bundle version instead.');
        }

        $this->dependencies->assertAcyclic();

        $requested = Module::query()->whereIn('id', $moduleIds)->get();
        $requestedIds = $requested->pluck('id')->all();

        $requiredByMap = []; // dependency module id => [requiring module codes]
        foreach ($requested as $module) {
            foreach ($this->dependencies->transitiveDependencies($module) as $dep) {
                $requiredByMap[$dep->id][] = $module->code;
            }
        }

        $autoAddedIds = array_diff(array_keys($requiredByMap), $requestedIds);
        $finalIds = array_values(array_unique([...$requestedIds, ...array_keys($requiredByMap)]));

        $bundle->modules()->sync($finalIds);

        $autoAddedModules = Module::query()->whereIn('id', $autoAddedIds)->get();

        return $autoAddedModules->map(fn ($module) => [
            'module' => $module,
            'required_by' => array_values(array_unique($requiredByMap[$module->id] ?? [])),
        ]);
    }

    public function deactivate(Bundle $bundle): Bundle
    {
        $bundle->update(['is_active' => false]);

        return $bundle;
    }

    public function reactivate(Bundle $bundle): Bundle
    {
        $bundle->update(['is_active' => true]);

        return $bundle;
    }

    public function delete(Bundle $bundle): void
    {
        $bundle->delete();
    }

    /**
     * Returns the set of module codes required (directly or transitively)
     * by the bundle's composition but missing from it.
     */
    public function missingDependencies(Bundle $bundle): Collection
    {
        $composedCodes = $bundle->modules()->pluck('code');

        $required = collect();
        foreach ($bundle->modules()->get() as $module) {
            $required = $required->merge($this->dependencies->transitiveDependencies($module)->pluck('code'));
        }

        return $required->unique()->diff($composedCodes)->values();
    }

    public function publish(Bundle $bundle, ?string $publishedByUserId = null): BundleVersion
    {
        if ($bundle->modules()->count() === 0) {
            throw new BundleException('Cannot publish a bundle with no modules.');
        }

        $missing = $this->missingDependencies($bundle);
        if ($missing->isNotEmpty()) {
            throw new BundleException(
                'Cannot publish bundle: unresolved module dependencies: '.$missing->implode(', ')
            );
        }

        return DB::transaction(function () use ($bundle, $publishedByUserId) {
            $nextVersion = ($bundle->versions()->max('version_number') ?? 0) + 1;

            $version = BundleVersion::query()->create([
                'bundle_id' => $bundle->id,
                'version_number' => $nextVersion,
                'status' => 'PUBLISHED',
                'published_at' => now(),
                'published_by' => $publishedByUserId,
            ]);

            $modules = $bundle->modules()->get();
            $version->modules()->attach(
                $modules->mapWithKeys(fn ($m) => [$m->id => ['module_code' => $m->code]])->all()
            );

            $bundle->update(['status' => 'PUBLISHED']);

            return $version;
        });
    }
}
