<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Services\ContractService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ContractApprovalRequest;
use App\Http\Requests\Platform\StoreContractRequest;
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
            array_merge($request->safe()->except(['tenant_id', 'items']), ['created_by' => $request->user()->id]),
            $request->input('items')
        );

        return $this->ok($contract->load('items'), 201);
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
