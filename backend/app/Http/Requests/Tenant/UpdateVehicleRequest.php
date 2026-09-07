<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $vehicleId = $this->route('vehicle')?->id;

        return [
            'vehicle_category_id' => ['sometimes', 'uuid', Rule::exists('vehicle_categories', 'id')->where(
                fn ($q) => $q->where(fn ($q2) => $q2->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            )],
            'brand' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['sometimes', 'string', 'max:50', Rule::unique('vehicles', 'registration_number')->where('tenant_id', $tenantId)->ignore($vehicleId)],
            'vin' => ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'vin')->where('tenant_id', $tenantId)->ignore($vehicleId)],
            'chassis_number' => ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'chassis_number')->where('tenant_id', $tenantId)->ignore($vehicleId)],
            'engine_number' => ['nullable', 'string', 'max:50'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'fuel_type' => ['nullable', 'string', 'max:50'],
            'transmission_type' => ['nullable', 'string', 'max:50'],
            'current_odometer' => ['sometimes', 'numeric', 'min:0'],
            'engine_hour' => ['nullable', 'numeric', 'min:0'],
            'operational_status' => ['nullable', 'in:AVAILABLE,IN_USE,ON_HOLD'],
        ];
    }
}
