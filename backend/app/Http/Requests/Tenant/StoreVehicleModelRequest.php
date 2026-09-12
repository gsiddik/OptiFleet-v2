<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_brand_id' => ['required', 'uuid', 'exists:vehicle_brands,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('vehicle_models', 'code')->where('vehicle_brand_id', $this->input('vehicle_brand_id'))],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
