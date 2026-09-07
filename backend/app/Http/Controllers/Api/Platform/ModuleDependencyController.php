<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreModuleDependencyRequest;

class ModuleDependencyController extends Controller
{
    public function __construct(private readonly ModuleDependencyService $dependencies) {}

    public function index(Module $module)
    {
        return $this->ok([
            'direct_dependencies' => $this->dependencies->directDependencies($module)->values(),
            'transitive_dependencies' => $this->dependencies->transitiveDependencies($module)->values(),
            'direct_dependents' => $this->dependencies->directDependents($module)->values(),
            'transitive_dependents' => $this->dependencies->transitiveDependents($module)->values(),
        ]);
    }

    public function store(StoreModuleDependencyRequest $request, Module $module)
    {
        $dependency = $this->dependencies->addDependency($module->id, $request->input('depends_on_module_id'));

        return $this->ok($dependency, 201);
    }

    public function destroy(Module $module, Module $dependsOn)
    {
        $this->dependencies->removeDependency($module->id, $dependsOn->id);

        return $this->message('Dependency removed.');
    }
}
