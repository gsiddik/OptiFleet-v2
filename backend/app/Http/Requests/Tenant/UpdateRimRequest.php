<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['sometimes', 'string', 'max:100'],
            'material' => ['nullable', 'string', 'max:100'],
            'width_inch' => ['nullable', 'numeric', 'min:0'],
            'diameter_inch' => ['nullable', 'numeric', 'min:0'],
            'disc_thickness_mm' => ['nullable', 'numeric', 'min:0'],
            'offset_mm' => ['nullable', 'numeric'],
            'bolt_holes' => ['nullable', 'integer', 'min:1'],
            'bolt_diameter_mm' => ['nullable', 'numeric', 'min:0'],
            'pcd_mm' => ['nullable', 'numeric', 'min:0'],
            'hub_hole_diameter_mm' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
