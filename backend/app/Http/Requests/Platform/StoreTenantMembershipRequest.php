<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required_without:existing_user_id', 'string', 'max:255'],
            'email' => ['required_without:existing_user_id', 'email'],
            'password' => ['required_without:existing_user_id', 'string', 'min:8'],
            'existing_user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ];
    }
}
