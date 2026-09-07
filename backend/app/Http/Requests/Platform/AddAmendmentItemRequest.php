<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class AddAmendmentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_type' => ['required', 'in:BUNDLE,MODULE,ADD_ON,CAPACITY,SETUP_FEE,OTHER'],
            'product_reference' => ['nullable', 'string'],
            'description' => ['required', 'string'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'billing_frequency' => ['nullable', 'in:MONTHLY,QUARTERLY,SEMIANNUAL,ANNUAL,CUSTOM'],
        ];
    }
}
