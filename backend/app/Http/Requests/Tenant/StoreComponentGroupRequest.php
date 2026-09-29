<?php

namespace App\Http\Requests\Tenant;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Http\Requests\Concerns\ValidatesComponentGroupAbbreviation;
use App\Support\TenantContext;
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
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('component_groups', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => $this->abbreviationRules(required: true),
            'parent_id' => ['nullable', 'uuid', ComponentGroup::selectableRule()],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
