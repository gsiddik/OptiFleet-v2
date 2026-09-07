<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'serial_number' => ['required', 'string', 'max:100', Rule::unique('tires', 'serial_number')->where('tenant_id', $tenantId)],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'tire_size' => ['nullable', 'string', 'max:50'],
            'pattern' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_months' => ['nullable', 'integer', 'min:0'],
            'warranty_km' => ['nullable', 'integer', 'min:0'],
            'current_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
        ];
    }
}
