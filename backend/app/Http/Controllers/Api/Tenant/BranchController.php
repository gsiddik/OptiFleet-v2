<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Organization\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreBranchRequest;
use App\Http\Requests\Tenant\UpdateBranchRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Branch::query();
        $this->scope->applyBranchScope($query, $user, $tenantId);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        $sort = $request->string('sort', 'name')->value();
        $direction = $request->string('direction', 'asc')->value() === 'desc' ? 'desc' : 'asc';
        if (in_array($sort, ['name', 'code', 'status', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreBranchRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $this->capacity->assertCanCreate($tenantId, 'branch');

        $branch = Branch::query()->create($request->validated() + ['status' => $request->input('status', 'DRAFT')]);

        return $this->ok($branch, 201);
    }

    public function show(Branch $branch)
    {
        $this->authorizeScope($branch);

        return $this->ok($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $this->authorizeScope($branch);

        $branch->update($request->validated());

        return $this->ok($branch);
    }

    public function activate(Branch $branch)
    {
        $this->authorizeScope($branch);
        $branch->update(['status' => 'ACTIVE']);

        return $this->ok($branch);
    }

    public function deactivate(Branch $branch)
    {
        $this->authorizeScope($branch);
        $branch->update(['status' => 'INACTIVE']);

        return $this->ok($branch);
    }

    private function authorizeScope(Branch $branch): void
    {
        // Explicit tenant-ownership check — never trust the implicit route-model
        // binding lookup alone for cross-tenant isolation (defense-in-depth).
        abort_unless($branch->tenant_id === $this->context->tenantId(), 404);

        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $branch->id),
            403,
            'This branch is outside your assigned data scope.'
        );
    }
}
