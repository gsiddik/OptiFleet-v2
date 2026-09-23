<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Next Improvement Tenant Portal - Products": "Fitur Product Categories
 * hanya dikelola oleh Superadmin" — Product Category management moves
 * from tenant-governed to platform-only. Every category created here is
 * platform (system) master data: tenant_id is always null, visible to
 * every tenant, exactly like the pre-existing platform-seeded categories.
 */
class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('product_categories', 'code')->whereNull('tenant_id')],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->whereNull('tenant_id')],
            'item_type' => ['nullable', 'in:SPARE_PART,TOOL,TIRE,CONSUMABLE,EQUIPMENT,RIM,OTHER'],
            'description' => ['nullable', 'string'],
            // Only meaningful for CONSUMABLE (Category or Subcategory) — drives whether that
            // category's Specification/Grade field is Conditional-Mandatory on the Product form.
            'requires_specification_grade' => ['nullable', 'boolean'],
        ];
    }
}
