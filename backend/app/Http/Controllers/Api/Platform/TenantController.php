<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Identity\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreTenantRequest;
use App\Http\Requests\Platform\UpdateTenantRequest;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $query = Tenant::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        $sort = $request->string('sort', 'created_at')->value();
        $direction = $request->string('direction', 'desc')->value() === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['name', 'code', 'status', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreTenantRequest $request)
    {
        $tenant = Tenant::query()->create($request->validated() + ['status' => $request->input('status', 'DRAFT')]);

        return $this->ok($tenant, 201);
    }

    public function show(Tenant $tenant)
    {
        return $this->ok($tenant);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $tenant->update($request->validated());

        return $this->ok($tenant);
    }

    public function activate(Tenant $tenant)
    {
        $tenant->update(['status' => 'ACTIVE']);

        return $this->ok($tenant);
    }

    public function deactivate(Tenant $tenant)
    {
        $tenant->update(['status' => 'INACTIVE']);

        return $this->ok($tenant);
    }
}
