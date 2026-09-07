<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        return $this->ok([
            'tenants_total' => Tenant::query()->count(),
            'tenants_active' => Tenant::query()->where('status', 'ACTIVE')->count(),
            'platform_users_total' => User::query()->where('user_type', 'platform')->count(),
            'tenant_users_total' => User::query()->where('user_type', 'tenant')->count(),
            'modules_total' => Module::query()->count(),
            'recent_audit_logs' => AuditLog::query()->latest('created_at')->limit(10)->get(),
        ]);
    }
}
