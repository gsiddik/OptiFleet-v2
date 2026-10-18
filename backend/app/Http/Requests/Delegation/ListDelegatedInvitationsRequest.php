<?php

namespace App\Http\Requests\Delegation;

use Illuminate\Foundation\Http\FormRequest;

class ListDelegatedInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', 'in:PENDING,ACCEPTED,REVOKED,EXPIRED'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
