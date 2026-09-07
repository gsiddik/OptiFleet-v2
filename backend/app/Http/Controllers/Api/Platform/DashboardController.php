<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoice\Models\Invoice;
use App\Domain\Payment\Models\Payment;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Subscription\Models\Subscription;
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

            // Commercial (Phase 2, Section 45)
            'subscriptions_pending' => Subscription::query()->where('status', 'PENDING')->count(),
            'subscriptions_active' => Subscription::query()->where('status', 'ACTIVE')->count(),
            'subscriptions_suspended' => Subscription::query()->where('status', 'SUSPENDED')->count(),
            'contracts_expiring' => \App\Domain\Contract\Models\Contract::query()->where('status', 'EXPIRING')->count(),
            'invoices_outstanding' => Invoice::query()->whereIn('status', ['OUTSTANDING', 'PARTIALLY_PAID'])->count(),
            'invoices_overdue' => Invoice::query()->where('status', 'OVERDUE')->count(),
            'payments_pending_verification' => Payment::query()->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW'])->count(),
        ]);
    }
}
