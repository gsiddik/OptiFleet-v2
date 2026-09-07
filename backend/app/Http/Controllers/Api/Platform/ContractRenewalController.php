<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Contract\Models\Contract;
use App\Domain\Contract\Services\RenewalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreRenewalRequest;

class ContractRenewalController extends Controller
{
    public function __construct(private readonly RenewalService $renewals) {}

    public function store(StoreRenewalRequest $request, Contract $contract)
    {
        $renewal = $this->renewals->createRenewalDraft(
            $contract,
            array_merge($request->safe()->except('items'), ['created_by' => $request->user()->id]),
            $request->input('items')
        );

        return $this->ok($renewal->load('items'), 201);
    }
}
