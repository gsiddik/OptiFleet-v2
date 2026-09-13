<?php

namespace App\Http\Requests\Tenant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('vehicle_brands', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'usage_type' => ['nullable', 'in:CAR,TRUCK,BUS,HEAVY_EQUIPMENT'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
