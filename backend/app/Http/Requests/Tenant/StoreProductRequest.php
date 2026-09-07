<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('products', 'code')->where('tenant_id', $tenantId)],
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'product_category_id' => ['required', 'uuid', Rule::exists('product_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'product_type' => ['required', 'in:SPARE_PART,TOOL,TIRE,CONSUMABLE,EQUIPMENT,OTHER'],
            'uom_id' => ['required', 'uuid', Rule::exists('uoms', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'track_serial_number' => ['nullable', 'boolean'],
            'track_batch' => ['nullable', 'boolean'],
        ];
    }
}
