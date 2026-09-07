<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class SyncComponentGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'component_group_ids' => ['required', 'array'],
            'component_group_ids.*' => ['uuid', 'exists:component_groups,id'],
        ];
    }
}
