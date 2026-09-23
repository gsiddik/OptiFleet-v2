<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'item_type' => ['sometimes', 'nullable', 'in:SPARE_PART,TOOL,TIRE,CONSUMABLE,EQUIPMENT,RIM,OTHER'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
            'requires_specification_grade' => ['sometimes', 'boolean'],
        ];
    }
}
