<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantCustomPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pricing_id' => ['required', 'uuid', 'exists:pricings,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tiers' => ['nullable', 'array'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'reason' => ['nullable', 'string'],
        ];
    }
}
