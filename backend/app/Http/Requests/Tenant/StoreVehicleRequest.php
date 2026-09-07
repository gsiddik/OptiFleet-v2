<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'default_workshop_id' => ['nullable', 'uuid', Rule::exists('workshops', 'id')->where('tenant_id', $tenantId)],
            'vehicle_category_id' => ['required', 'uuid', Rule::exists('vehicle_categories', 'id')->where(
                fn ($q) => $q->where(fn ($q2) => $q2->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            )],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['required', 'string', 'max:50', Rule::unique('vehicles', 'registration_number')->where('tenant_id', $tenantId)],
            'vin' => ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'vin')->where('tenant_id', $tenantId)],
            'chassis_number' => ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'chassis_number')->where('tenant_id', $tenantId)],
            'engine_number' => ['nullable', 'string', 'max:50'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'fuel_type' => ['nullable', 'string', 'max:50'],
            'transmission_type' => ['nullable', 'string', 'max:50'],
            'current_odometer' => ['nullable', 'numeric', 'min:0'],
            'engine_hour' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,IN_MAINTENANCE,BREAKDOWN,OUT_OF_SERVICE,INACTIVE,DISPOSED'],
            'operational_status' => ['nullable', 'in:AVAILABLE,IN_USE,ON_HOLD'],
        ];
    }
}
