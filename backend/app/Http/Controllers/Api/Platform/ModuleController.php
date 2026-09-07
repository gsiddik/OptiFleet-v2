<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\ProductCatalog\Models\Module;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreModuleRequest;
use App\Http\Requests\Platform\UpdateModuleRequest;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function index(Request $request)
    {
        $query = Module::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if ($category = $request->string('category')->value()) {
            $query->where('category', $category);
        }

        return $this->ok($query->orderBy('category')->orderBy('name')->get());
    }

    public function store(StoreModuleRequest $request)
    {
        $module = Module::query()->create($request->validated());

        return $this->ok($module, 201);
    }

    public function show(Module $module)
    {
        return $this->ok($module->load(['dependencies', 'dependents']));
    }

    public function update(UpdateModuleRequest $request, Module $module)
    {
        $module->update($request->validated());

        return $this->ok($module);
    }
}
