<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Organization\Models\Workshop;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWorkshopRequest;
use App\Http\Requests\Tenant\UpdateWorkshopRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkshopController extends Controller
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

        $query = Workshop::query();
        $this->scope->applyWorkshopScope($query, $user, $tenantId);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($branchId = $request->string('branch_id')->value()) {
            $query->where('branch_id', $branchId);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreWorkshopRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $this->capacity->assertCanCreate($tenantId, 'workshop');

        $workshop = Workshop::query()->create($request->validated() + ['status' => $request->input('status', 'DRAFT')]);

        return $this->ok($workshop, 201);
    }

    public function show(Workshop $workshop)
    {
        $this->authorizeScope($workshop);

        return $this->ok($workshop);
    }

    public function update(UpdateWorkshopRequest $request, Workshop $workshop)
    {
        $this->authorizeScope($workshop);

        $workshop->update($request->validated());

        return $this->ok($workshop);
    }

    public function activate(Workshop $workshop)
    {
        $this->authorizeScope($workshop);
        $workshop->update(['status' => 'ACTIVE']);

        return $this->ok($workshop);
    }

    public function deactivate(Workshop $workshop)
    {
        $this->authorizeScope($workshop);
        $workshop->update(['status' => 'INACTIVE']);

        return $this->ok($workshop);
    }

    private function authorizeScope(Workshop $workshop): void
    {
        abort_unless($workshop->tenant_id === $this->context->tenantId(), 404);

        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workshop->id),
            403,
            'This workshop is outside your assigned data scope.'
        );
    }
}
