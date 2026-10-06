<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\TenantLogoService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * G-15: previously a tenant had no way to view or maintain its own
 * company-profile data (tax ID, address, contact info) — only a platform
 * admin could set legal_name/industry via the platform Tenant endpoint.
 */
class CompanyProfileController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantLogoService $logos,
    ) {}

    public function show()
    {
        return $this->ok(Tenant::query()->findOrFail($this->context->tenantId()));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'workshop_working_days' => ['required', 'integer', 'in:5,6,7'],
            // i18n: the company's default language for users who have not chosen one.
            'default_locale' => ['sometimes', 'nullable', Rule::in(DocumentLocale::SUPPORTED)],
        ]);

        $tenant = Tenant::query()->findOrFail($this->context->tenantId());
        $tenant->update($validated);

        return $this->ok($tenant->fresh());
    }

    public function uploadLogo(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png']]);

        $tenant = Tenant::query()->findOrFail($this->context->tenantId());
        $tenant = $this->logos->upload($tenant, $request->file('file'));

        return $this->ok($tenant);
    }
}
