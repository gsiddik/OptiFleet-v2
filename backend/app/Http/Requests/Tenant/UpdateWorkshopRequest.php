<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkshopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('workshops', 'code')->where('tenant_id', $tenantId)->ignore($this->route('workshop'))],
            'name' => ['sometimes', 'string', 'max:255'],
            'branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'workshop_type' => ['nullable', 'in:INTERNAL,SATELLITE,MOBILE'],
            'address' => ['nullable', 'string'],
            'pic' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'number_of_service_bays' => ['nullable', 'integer', 'min:0'],
            'operational_hours' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:DRAFT,ACTIVE,INACTIVE,CLOSED'],
        ];
    }
}
