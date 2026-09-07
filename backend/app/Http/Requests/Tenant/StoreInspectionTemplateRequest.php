<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInspectionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'vehicle_category_id' => ['nullable', 'uuid', Rule::exists('vehicle_categories', 'id')->where(
                fn ($q) => $q->where(fn ($q2) => $q2->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            )],
            'inspection_type' => ['required', 'in:PRE_TRIP,POST_TRIP,PERIODIC,WORKSHOP,MAINTENANCE'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
