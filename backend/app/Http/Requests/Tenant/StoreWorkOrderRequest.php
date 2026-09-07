<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
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
            'workshop_id' => ['nullable', 'uuid', Rule::exists('workshops', 'id')->where('tenant_id', $tenantId)],
            'maintenance_type' => ['required', 'in:PREVENTIVE,CORRECTIVE,BREAKDOWN,INSPECTION,CAMPAIGN'],
            'priority' => ['nullable', 'in:LOW,MEDIUM,HIGH,URGENT'],
            'complaint' => ['nullable', 'string'],
            'current_odometer' => ['nullable', 'numeric', 'min:0'],
            'engine_hour' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
