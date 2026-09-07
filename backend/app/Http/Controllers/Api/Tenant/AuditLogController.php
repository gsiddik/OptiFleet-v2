<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->where('tenant_id', $this->context->tenantId())
            ->with('actor')
            ->latest('created_at');

        if ($resourceType = $request->string('resource_type')->value()) {
            $query->where('resource_type', $resourceType);
        }
        if ($resourceId = $request->string('resource_id')->value()) {
            $query->where('resource_id', $resourceId);
        }
        if ($action = $request->string('action')->value()) {
            $query->where('action', $action);
        }
        if ($from = $request->string('from')->value()) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->value()) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 25)), fn (AuditLog $log) => [
            'id' => $log->id,
            'actor_user_id' => $log->actor_user_id,
            'actor_name' => $log->actor?->name,
            'tenant_id' => $log->tenant_id,
            'resource_type' => $log->resource_type,
            'resource_id' => $log->resource_id,
            'action' => $log->action,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at,
        ]);
    }
}
