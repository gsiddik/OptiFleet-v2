<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\Concerns\ValidatesComponentGroupAbbreviation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComponentGroupRequest extends FormRequest
{
    use ValidatesComponentGroupAbbreviation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Unique among platform groups INCLUDING soft-deleted ones, so a
            // retired group can always be restored without a code clash.
            'code' => ['required', 'string', 'max:50', Rule::unique('component_groups', 'code')->whereNull('tenant_id')],
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => $this->abbreviationRules(required: true),
            'parent_id' => ['nullable', 'uuid', Rule::exists('component_groups', 'id')->whereNull('tenant_id')->whereNull('deleted_at')],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
