<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class SyncVehicleCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_category_ids' => ['required', 'array'],
            'vehicle_category_ids.*' => ['uuid', 'exists:vehicle_categories,id'],
        ];
    }
}
