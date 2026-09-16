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
        if ($request->boolean('active_only')) {
            // Used by the Contract Form's BUNDLE Product Reference dropdown
            // (Section 10.2/7.1): only a published, active bundle can be
            // selected for a new line item. Soft-deleted rows are already
            // excluded by Bundle's default query scope.
            $query->where('is_active', true)->where('status', 'PUBLISHED');
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
        $autoAdded = $this->bundles->syncModules($bundle, $request->input('module_ids'));

        return $this->ok([
            'bundle' => $bundle->fresh('modules'),
            'auto_added' => $autoAdded->map(fn ($entry) => [
                'module' => $entry['module'],
                'required_by' => $entry['required_by'],
            ])->values(),
        ]);
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

    public function deactivate(Bundle $bundle)
    {
        return $this->ok($this->bundles->deactivate($bundle)->fresh());
    }

    public function reactivate(Bundle $bundle)
    {
        return $this->ok($this->bundles->reactivate($bundle)->fresh());
    }

    public function destroy(Bundle $bundle)
    {
        $this->bundles->delete($bundle);

        return $this->ok(['deleted' => true]);
    }
}
