<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AuditService
{
    public function __construct(private readonly TenantContext $context) {}

    public function log(string $resourceType, string $resourceId, string $action, ?array $oldValues = null, ?array $newValues = null, ?string $tenantId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'actor_user_id' => $this->context->user()?->id,
            'tenant_id' => $tenantId ?? $this->context->tenantId(),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
            'created_at' => now(),
        ]);
    }
}
