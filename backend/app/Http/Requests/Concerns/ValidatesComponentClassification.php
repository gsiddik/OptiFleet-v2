<?php

namespace App\Http\Requests\Concerns;

use App\Domain\MasterData\Models\ComponentSubcategory;
use Illuminate\Validation\Rule;

/**
 * Shape validation for Component Category / Subcategory requests (tenant and
 * platform). Codes are normalized to UPPER_SNAKE_CASE-compatible input
 * (trimmed, uppercased); hierarchy, uniqueness, effective availability and
 * usage rules live in ComponentClassificationService.
 */
trait ValidatesComponentClassification
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    protected function codeRules(): array
    {
        return ['required', 'string', 'max:80', 'regex:/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/'];
    }

    protected function commonRules(bool $creating): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$sometimes, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }

    protected function itemTypeRules(): array
    {
        return [
            'item_types' => ['sometimes', 'array'],
            'item_types.*' => ['string', Rule::in(ComponentSubcategory::ITEM_TYPES)],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => 'Code must be UPPER_SNAKE_CASE (letters, digits and single underscores), e.g. DISC_BRAKE.'];
    }
}
