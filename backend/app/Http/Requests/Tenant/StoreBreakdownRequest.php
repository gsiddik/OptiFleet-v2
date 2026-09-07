<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBreakdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'location' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'in:MINOR,MAJOR,IMMOBILIZED'],
            'description' => ['required', 'string'],
            'evidence' => ['nullable', 'string', 'max:255'],
        ];
    }
}
