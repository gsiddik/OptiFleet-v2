<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\Identity\Models\TenantUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\StoreDataScopeRequest;
use App\Support\TenantContext;

class DataScopeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(TenantUser $tenantUser)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($tenantUser->tenant_id === $tenantId, 404);

        $assignments = DataScopeAssignment::query()
            ->where('user_id', $tenantUser->user_id)
            ->where('tenant_id', $tenantId)
            ->get();

        return $this->ok($assignments);
    }

    public function store(StoreDataScopeRequest $request, TenantUser $tenantUser)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($tenantUser->tenant_id === $tenantId, 404);

        $assignment = DataScopeAssignment::query()->firstOrCreate([
            'user_id' => $tenantUser->user_id,
            'tenant_id' => $tenantId,
            'scope_type' => $request->input('scope_type'),
            'scope_resource_id' => $request->input('scope_resource_id'),
        ]);

        return $this->ok($assignment, 201);
    }

    public function destroy(TenantUser $tenantUser, DataScopeAssignment $dataScopeAssignment)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($tenantUser->tenant_id === $tenantId, 404);
        abort_unless($dataScopeAssignment->tenant_id === $tenantId && $dataScopeAssignment->user_id === $tenantUser->user_id, 404);

        $dataScopeAssignment->delete();

        return $this->message('Data scope removed.');
    }
}
