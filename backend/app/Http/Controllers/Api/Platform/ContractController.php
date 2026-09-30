<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Services\ContractService;
use App\Domain\Identity\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ContractApprovalRequest;
use App\Http\Requests\Platform\StoreContractRequest;
use App\Http\Requests\Platform\UpdateContractRequest;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(private readonly ContractService $contracts) {}

    public function index(Request $request)
    {
        $query = Contract::query()->with('tenant');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($tenantId = $request->string('tenant_id')->value()) {
            $query->where('tenant_id', $tenantId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreContractRequest $request)
    {
        $contract = $this->contracts->createDraft(
            $request->input('tenant_id'),
            array_merge($request->safe()->except(['tenant_id', 'items']), [
                'created_by' => $request->user()->id,
                'source_context' => 'CONTRACT_MANAGEMENT',
            ]),
            $request->input('items')
        );

        return $this->ok($contract->load('items'), 201);
    }

    /**
     * Section 6.3/9.1: contract creation from a Tenant Detail page. The
     * tenant comes from the route (authorized via the normal
     * permission:contract.create + platform.scope middleware chain), never
     * from the request body — StoreContractRequest doesn't even require
     * tenant_id on this route, and any tenant_id the client sends here is
     * simply ignored, so a manipulated payload can't target another tenant.
     */
    public function storeForTenant(StoreContractRequest $request, Tenant $tenant)
    {
        $contract = $this->contracts->createDraft(
            $tenant->id,
            array_merge($request->safe()->except(['tenant_id', 'items']), [
                'created_by' => $request->user()->id,
                'source_context' => 'TENANT_MANAGEMENT',
            ]),
            $request->input('items')
        );

        return $this->ok($contract->load('items'), 201);
    }

    public function update(UpdateContractRequest $request, Contract $contract)
    {
        $contract = $this->contracts->updateDraft(
            $contract,
            $request->safe()->except(['items']),
            $request->input('items')
        );

        return $this->ok($contract->load('items'));
    }

    public function show(Contract $contract)
    {
        return $this->ok($contract->load(['items', 'approvals.approver', 'amendments', 'subscription', 'tenant']));
    }

    public function submitForApproval(Contract $contract)
    {
        $this->contracts->submitForApproval($contract);

        return $this->ok($contract->fresh());
    }

    public function approve(ContractApprovalRequest $request, Contract $contract)
    {
        $updated = $this->contracts->approve($contract, $request->user()->id, $request->input('note'));

        return $this->ok($updated->load(['subscription', 'items']));
    }

    public function reject(ContractApprovalRequest $request, Contract $contract)
    {
        $updated = $this->contracts->reject($contract, $request->user()->id, $request->input('note'));

        return $this->ok($updated);
    }

    public function terminate(ContractApprovalRequest $request, Contract $contract)
    {
        $updated = $this->contracts->terminate($contract, $request->input('note'));

        return $this->ok($updated);
    }
}
