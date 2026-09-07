<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComponentGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('component_groups', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', Rule::exists('component_groups', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
