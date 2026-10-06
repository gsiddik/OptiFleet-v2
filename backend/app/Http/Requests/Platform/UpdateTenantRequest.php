<?php

namespace App\Http\Requests\Platform;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('tenants', 'code')->ignore($this->route('tenant'))],
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            // i18n: the tenant's default language; null = none (users then fall back to their browser / English).
            'default_locale' => ['sometimes', 'nullable', Rule::in(DocumentLocale::SUPPORTED)],
        ];
    }
}
