<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenancePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('maintenance_packages', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'maintenance_type' => ['required', 'in:PREVENTIVE,CORRECTIVE,BREAKDOWN,INSPECTION,CAMPAIGN'],
            'description' => ['nullable', 'string'],
            'standard_labor_hours' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
