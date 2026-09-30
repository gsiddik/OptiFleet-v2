<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:255'],
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
