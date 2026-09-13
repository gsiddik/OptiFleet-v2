<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('workers', 'employee_code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'workshop_id' => ['nullable', 'uuid', Rule::exists('workshops', 'id')->where('tenant_id', $tenantId)],
            'worker_type' => ['required', 'in:LEAD_MECHANIC,MECHANIC,TECHNICIAN,INSPECTOR,QC'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'photo_url' => ['nullable', 'string', 'max:255'],
        ];
    }
}
