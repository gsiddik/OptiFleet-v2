<?php

namespace App\Http\Requests\AccessControl;

use Illuminate\Foundation\Http\FormRequest;

class StoreDataScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope_type' => ['required', 'in:TENANT,BRANCH,WORKSHOP,WAREHOUSE,OWN'],
            'scope_resource_id' => ['nullable', 'uuid', 'required_unless:scope_type,TENANT,OWN'],
        ];
    }
}
