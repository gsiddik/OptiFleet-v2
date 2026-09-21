<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('product_categories', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('product_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            // Only meaningful for a top-level category (no parent_id) — a
            // subcategory inherits its parent's item_type server-side so it
            // can never drift from it (see ProductCategoryController::store).
            'item_type' => ['nullable', 'in:SPARE_PART,TOOL,TIRE,CONSUMABLE,EQUIPMENT,RIM,OTHER'],
            'description' => ['nullable', 'string'],
        ];
    }
}
