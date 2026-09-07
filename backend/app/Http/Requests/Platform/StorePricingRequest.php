<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class StorePricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'priceable_type' => ['required', 'in:MODULE,BUNDLE,ADD_ON,CAPACITY'],
            'priceable_code' => ['required', 'string', 'max:100'],
            'pricing_method' => ['required', 'in:FLAT,PER_VEHICLE,PER_USER,PER_BRANCH,PER_WORKSHOP,PER_WAREHOUSE,TIERED,CUSTOM'],
            'billing_frequency' => ['required', 'in:MONTHLY,QUARTERLY,SEMIANNUAL,ANNUAL,CUSTOM'],
            'currency' => ['nullable', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tiers' => ['nullable', 'array'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
