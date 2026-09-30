<?php

namespace App\Http\Requests\Platform;

/**
 * Editing a DRAFT contract (`contract.update`) takes the same payload as creating one;
 * the tenant is fixed at creation and is never taken from this request.
 */
class UpdateContractRequest extends StoreContractRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['tenant_id']);

        return $rules;
    }
}
