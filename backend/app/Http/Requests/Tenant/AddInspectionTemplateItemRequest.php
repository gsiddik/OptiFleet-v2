<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class AddInspectionTemplateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'component_group_id' => ['nullable', 'uuid', \App\Domain\MasterData\Models\ComponentGroup::selectableRule()],
            'item_text' => ['required', 'string', 'max:255'],
            'input_type' => ['required', 'in:CHECKBOX,PASS_FAIL,TEXT,NUMBER,SELECT,PHOTO'],
            'options' => ['nullable', 'array'],
            'required' => ['boolean'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'threshold' => ['nullable', 'string', 'max:255'],
        ];
    }
}
