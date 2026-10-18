<?php

namespace App\Http\Requests\Delegation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invite a new person to the centrally governed tenant. There is deliberately no password and no token field: OptiNexus e-mails the
 * link and the person sets their own password on an OptiNexus page. `locale` is the language of that e-mail and page; when it is
 * left out, the language of the request (the UI language) is used.
 */
class InviteDelegatedUserRequest extends FormRequest
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
            'locale' => ['sometimes', 'nullable', 'string', 'in:en,id'],
        ];
    }
}
