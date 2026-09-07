<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SyncBundleModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module_ids' => ['required', 'array'],
            'module_ids.*' => ['uuid', 'exists:modules,id'],
        ];
    }
}
