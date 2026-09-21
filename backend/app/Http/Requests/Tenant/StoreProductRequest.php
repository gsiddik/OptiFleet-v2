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
            // "Next Improvement Tenant Portal - Products" Section 20: Item Code is
            // server-generated (ProductController::store) and never accepted from
            // the client — deliberately absent from this rule set.
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'product_category_id' => ['required', 'uuid', Rule::exists('product_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'product_type' => ['required', 'in:SPARE_PART,TOOL,TIRE,CONSUMABLE,EQUIPMENT,RIM,OTHER'],
            'uom_id' => ['required', 'uuid', Rule::exists('uoms', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'brand' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:100'],
            'production_year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_mm' => ['nullable', 'numeric', 'min:0'],
            'width_mm' => ['nullable', 'numeric', 'min:0'],
            'height_mm' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:255'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'track_serial_number' => ['nullable', 'boolean'],
            'track_batch' => ['nullable', 'boolean'],
            // Phase F / BD-3: reference tread depth for TIRE products — the "KTN" source for scoring.
            'reference_tread_depth_mm' => ['nullable', 'numeric', 'min:0.01'],
        ];
    }
}
