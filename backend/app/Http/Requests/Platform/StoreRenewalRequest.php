<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class StoreRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'end_date' => ['required', 'date'],
            'billing_cycle' => ['nullable', 'in:MONTHLY,QUARTERLY,SEMIANNUAL,ANNUAL,CUSTOM'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
            'activation_requires_payment' => ['boolean'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_type' => ['required', 'in:BUNDLE,MODULE,ADD_ON,CAPACITY,SETUP_FEE,OTHER'],
            'items.*.product_reference' => ['nullable', 'string'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.billing_frequency' => ['required', 'in:MONTHLY,QUARTERLY,SEMIANNUAL,ANNUAL,CUSTOM'],
        ];
    }
}
