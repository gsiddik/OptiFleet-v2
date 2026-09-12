<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Identity\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * G-15: previously a tenant had no way to view or maintain its own
 * company-profile data (tax ID, address, contact info) — only a platform
 * admin could set legal_name/industry via the platform Tenant endpoint.
 */
class CompanyProfileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

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
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = Tenant::query()->findOrFail($this->context->tenantId());
        $tenant->update($validated);

        return $this->ok($tenant->fresh());
    }
}
