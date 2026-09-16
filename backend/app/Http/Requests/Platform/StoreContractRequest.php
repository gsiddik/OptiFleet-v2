<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // When this request is bound to a route with a {tenant} segment
            // (POST /platform/tenants/{tenant}/contracts — Tenant Management
            // entry point), the tenant is taken from the route, never from
            // the body, so tenant_id in the payload is neither required nor
            // trusted there (the controller ignores it). Section 6.3/9.1.
            'tenant_id' => [Rule::requiredIf(fn () => $this->route('tenant') === null), 'uuid', 'exists:tenants,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'billing_cycle' => ['required', 'in:MONTHLY,QUARTERLY,SEMIANNUAL,ANNUAL,CUSTOM'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'activation_requires_payment' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_type' => ['required', 'in:BUNDLE,MODULE,ADD_ON,CAPACITY,SETUP_FEE,OTHER'],
            'items.*.product_reference' => ['nullable', 'string'],
            'items.*.description' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.billing_frequency' => ['required', 'in:MONTHLY,QUARTERLY,SEMIANNUAL,ANNUAL,CUSTOM'],
            'items.*.valid_from' => ['nullable', 'date'],
            'items.*.valid_until' => ['nullable', 'date'],
        ];
    }
}
