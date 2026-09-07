<?php

namespace App\Http\Controllers\Api\Tenant\Account;

use App\Domain\Contract\Models\Contract;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;

class AccountContractController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show()
    {
        $contract = Contract::query()
            ->where('tenant_id', $this->context->tenantId())
            ->whereIn('status', ['ACTIVE', 'EXPIRING', 'APPROVED'])
            ->with('items')
            ->latest('created_at')
            ->first();

        return $this->ok($contract);
    }

    public function index()
    {
        $contracts = Contract::query()
            ->where('tenant_id', $this->context->tenantId())
            ->latest('created_at')
            ->get();

        return $this->ok($contracts);
    }
}
