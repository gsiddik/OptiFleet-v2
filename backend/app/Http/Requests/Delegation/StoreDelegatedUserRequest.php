<?php

namespace App\Http\Requests\Delegation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add (or link) a person to the centrally governed tenant. There is deliberately no password field: OptiFleet never sees or
 * stores credentials of centrally governed users; the person authenticates in OptiNexus.
 */
class StoreDelegatedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim($this->input('email'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role_ids' => ['sometimes', 'array', 'max:20'],
            'role_ids.*' => ['uuid'],
        ];
    }
}
