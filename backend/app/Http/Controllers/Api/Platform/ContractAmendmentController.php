<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Models\ContractAmendment;
use App\Domain\Contract\Services\AmendmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\AddAmendmentItemRequest;
use App\Http\Requests\Platform\ContractApprovalRequest;
use App\Http\Requests\Platform\StoreAmendmentRequest;
use Illuminate\Http\Request;

class ContractAmendmentController extends Controller
{
    public function __construct(private readonly AmendmentService $amendments) {}

    public function index(Contract $contract)
    {
        return $this->ok($contract->amendments()->with('items')->latest('amendment_number')->get());
    }

    public function store(StoreAmendmentRequest $request, Contract $contract)
    {
        $amendment = $this->amendments->createDraft(
            $contract,
            $request->input('reason'),
            $request->input('effective_date'),
            $request->user()->id
        );

        return $this->ok($amendment, 201);
    }

    public function addItem(AddAmendmentItemRequest $request, Contract $contract, ContractAmendment $amendment)
    {
        abort_unless($amendment->contract_id === $contract->id, 404);

        $item = $this->amendments->addItem($amendment, $request->validated());

        return $this->ok($item, 201);
    }

    public function removeItem(Request $request, Contract $contract, ContractAmendment $amendment)
    {
        abort_unless($amendment->contract_id === $contract->id, 404);

        $item = $this->amendments->removeItem($amendment, $request->input('contract_item_id'));

        return $this->ok($item, 201);
    }

    public function submitForApproval(Contract $contract, ContractAmendment $amendment)
    {
        abort_unless($amendment->contract_id === $contract->id, 404);

        return $this->ok($this->amendments->submitForApproval($amendment));
    }

    public function approve(Request $request, Contract $contract, ContractAmendment $amendment)
    {
        abort_unless($amendment->contract_id === $contract->id, 404);

        return $this->ok($this->amendments->approve($amendment, $request->user()->id));
    }

    public function reject(ContractApprovalRequest $request, Contract $contract, ContractAmendment $amendment)
    {
        abort_unless($amendment->contract_id === $contract->id, 404);

        return $this->ok($this->amendments->reject($amendment, $request->input('note')));
    }
}
