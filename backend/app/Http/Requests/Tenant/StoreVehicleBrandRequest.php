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
            // "Next Improvement Tenant Portal - Products": Brand Of is now a
            // multi-select; the single-value 'usage_type' is kept accepted
            // for backward compatibility but no longer written by the UI.
            'usage_type' => ['nullable', 'in:CAR,TRUCK,BUS,HEAVY_EQUIPMENT'],
            'usage_types' => ['nullable', 'array'],
            'usage_types.*' => ['in:CAR,TRUCK,BUS,HEAVY_EQUIPMENT'],
            // "Brand Of": Vehicle Category master ids (active ones; checked in the controller).
            'vehicle_category_ids' => ['nullable', 'array'],
            'vehicle_category_ids.*' => ['uuid', 'distinct'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
