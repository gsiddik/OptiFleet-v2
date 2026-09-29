<?php

namespace App\Http\Requests\Concerns;

/**
 * Component Group Abbreviation input handling shared by the tenant and
 * platform create/update requests: surrounding whitespace is trimmed and
 * lowercase letters are normalized to uppercase ("eng" -> "ENG"); anything
 * that is then not exactly three letters A-Z is rejected. Uniqueness and the
 * post-usage lock are enforced by ComponentGroupService.
 */
trait ValidatesComponentGroupAbbreviation
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('abbreviation'))) {
            $this->merge(['abbreviation' => strtoupper(trim($this->input('abbreviation')))]);
        }
    }

    protected function abbreviationRules(bool $required): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'];
    }

    public function messages(): array
    {
        return [
            'abbreviation.size' => 'The abbreviation must be exactly 3 letters.',
            'abbreviation.regex' => 'The abbreviation must contain letters A-Z only (e.g. ENG, BRK, HYD).',
        ];
    }
}
