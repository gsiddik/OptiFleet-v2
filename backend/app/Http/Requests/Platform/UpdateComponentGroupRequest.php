<?php

namespace App\Http\Requests\Platform;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Http\Requests\Concerns\ValidatesComponentGroupAbbreviation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComponentGroupRequest extends FormRequest
{
    use ValidatesComponentGroupAbbreviation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var ComponentGroup|null $group */
        $group = $this->route('componentGroup');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'abbreviation' => $this->abbreviationRules(required: false),
            'parent_id' => [
                'nullable', 'uuid', Rule::notIn([$group?->id]),
                Rule::exists('component_groups', 'id')->whereNull('tenant_id')->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('id', $group?->parent_id)),
            ],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
