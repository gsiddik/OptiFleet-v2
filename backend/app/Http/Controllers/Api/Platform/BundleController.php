<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Services\BundleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreBundleRequest;
use App\Http\Requests\Platform\SyncBundleModulesRequest;
use App\Http\Requests\Platform\UpdateBundleRequest;
use Illuminate\Http\Request;

class BundleController extends Controller
{
    public function __construct(private readonly BundleService $bundles) {}

    public function index(Request $request)
    {
        $query = Bundle::query()->with('modules');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->ok($query->orderBy('name')->get());
    }

    public function store(StoreBundleRequest $request)
    {
        $bundle = Bundle::query()->create($request->safe()->except('module_ids') + ['status' => 'DRAFT']);

        if ($moduleIds = $request->input('module_ids')) {
            $this->bundles->syncModules($bundle, $moduleIds);
        }

        return $this->ok($bundle->fresh('modules'), 201);
    }

    public function show(Bundle $bundle)
    {
        return $this->ok($bundle->load(['modules', 'versions.modules']));
    }

    public function update(UpdateBundleRequest $request, Bundle $bundle)
    {
        $bundle->update($request->validated());

        return $this->ok($bundle->fresh('modules'));
    }

    public function syncModules(SyncBundleModulesRequest $request, Bundle $bundle)
    {
        $this->bundles->syncModules($bundle, $request->input('module_ids'));

        return $this->ok($bundle->fresh('modules'));
    }

    public function missingDependencies(Bundle $bundle)
    {
        return $this->ok($this->bundles->missingDependencies($bundle));
    }

    public function publish(Request $request, Bundle $bundle)
    {
        $version = $this->bundles->publish($bundle, $request->user()->id);

        return $this->ok($version->load('modules'), 201);
    }
}
