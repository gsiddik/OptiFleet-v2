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
            // Section (Mechanic): Worker Type is now tenant-manageable master data
            // (worker_type_id) rather than the fixed 5-value legacy enum. Either one
            // satisfies Create — worker_type stays accepted for any caller that hasn't
            // migrated, worker_type_id is what the current frontend actually sends.
            'worker_type' => ['required_without:worker_type_id', 'in:LEAD_MECHANIC,MECHANIC,TECHNICIAN,INSPECTOR,QC'],
            'worker_type_id' => ['required_without:worker_type', 'nullable', 'uuid', Rule::exists('worker_types', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'photo_url' => ['nullable', 'string', 'max:255'],
        ];
    }
}
