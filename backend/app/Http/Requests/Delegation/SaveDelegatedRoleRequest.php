<?php

namespace App\Http\Requests\Delegation;

use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Governance\GovernanceCatalog;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or change a custom role of the centrally governed tenant. What may be put into it is decided by OptiNexus (the
 * delegable set); here only the shape is checked, and that a permission key belongs to this application.
 */
class SaveDelegatedRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Changing which permissions a role holds is a separate right from renaming it (as in the local role editor).
        if ($this->isMethod('PUT') && $this->has('permissions')) {
            $context = app(TenantContext::class);

            return $this->user() !== null && app(PermissionService::class)->userHasPermission($this->user(), 'role.assign_permission', $context->tenantId());
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $prefix = GovernanceCatalog::application().'.';

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'permissions.*' => ['string', 'max:150', fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && str_starts_with($value, $prefix) ? null : $fail('The permission does not belong to this application.')],
        ];
    }
}
