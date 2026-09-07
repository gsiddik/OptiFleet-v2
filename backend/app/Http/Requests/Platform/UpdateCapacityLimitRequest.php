<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCapacityLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_type' => ['required', 'in:vehicle,user,branch,workshop,warehouse'],
            'max_count' => ['required', 'integer', 'min:0'],
        ];
    }
}
