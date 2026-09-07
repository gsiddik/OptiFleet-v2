<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('warehouses', 'code')->where('tenant_id', $tenantId)->ignore($this->route('warehouse'))],
            'name' => ['sometimes', 'string', 'max:255'],
            'branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'workshop_id' => ['nullable', 'uuid', Rule::exists('workshops', 'id')->where('tenant_id', $tenantId)],
            'warehouse_type' => ['nullable', 'in:CENTRAL,BRANCH,WORKSHOP,TIRE,CONSUMABLE,SCRAP,QUARANTINE'],
            'address' => ['nullable', 'string'],
            'pic' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:DRAFT,ACTIVE,INACTIVE,CLOSED'],
        ];
    }
}
