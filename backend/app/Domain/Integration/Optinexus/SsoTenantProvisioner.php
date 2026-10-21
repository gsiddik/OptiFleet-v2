<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Governance\NexusGovernanceClient;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates a separate CENTRAL projection, never relinks or revives an existing tenant. */
class SsoTenantProvisioner
{
    public function __construct(
        private readonly NexusGovernanceClient $nexus,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $claims Verified OIDC claims only. */
    public function provision(array $claims): Tenant
    {
        if (! config('optinexus.enabled') || ! config('optinexus.sso.provision_tenants')) {
            throw new SsoException('tenant_not_linked');
        }
        if (($claims['email_verified'] ?? false) !== true || empty($claims['email'])) {
            throw new SsoException('email_not_verified');
        }

        $nexusTenantId = $claims['tenant_id'];
        $application = (string) config('optinexus.governance.application_code');
        // Fresh server-to-server checks: a signed token alone does not establish current entitlement.
        $context = $this->nexus->productContext($claims['sub'], $nexusTenantId);
        if (! $context['allowed'] || ($context['tenant']['id'] ?? null) !== $nexusTenantId
            || ! in_array($application, array_column($context['products'], 'code'), true)) {
            throw new SsoException('access_denied');
        }
        $commercial = $this->nexus->commercialContext($nexusTenantId);
        if ($commercial['tenant_status'] !== 'ACTIVE'
            || ! in_array($application, $commercial['applications'], true)) {
            throw new SsoException('tenant_not_entitled');
        }

        try {
            return DB::transaction(function () use ($context, $nexusTenantId) {
                // Include deleted rows: their identifiers and data still belong to that customer.
                $existing = Tenant::withTrashed()->where('optinexus_tenant_id', $nexusTenantId)->first();
                if ($existing) {
                    return $this->active($existing);
                }

                $code = trim((string) ($context['tenant']['code'] ?? ''));
                if ($code === '' || strlen($code) > 255 || Tenant::withTrashed()->where('code', $code)->exists()) {
                    $code = 'NEXUS-'.$nexusTenantId;
                }
                $name = trim((string) ($context['tenant']['name'] ?? ''));
                $tenant = new Tenant;
                $tenant->forceFill([
                    'code' => $code,
                    'name' => Str::limit($name !== '' ? $name : $code, 255, ''),
                    'status' => 'ACTIVE',
                    'optinexus_tenant_id' => $nexusTenantId,
                    'governance_mode' => 'CENTRAL',
                ])->save();
                $this->audit->log('Tenant', $tenant->id, 'provisioned_from_optinexus', null, [
                    'optinexus_tenant_id' => $nexusTenantId, 'governance_mode' => 'CENTRAL',
                ], $tenant->id);

                return $tenant;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent first login may have inserted the same Nexus ID; never adopt a code match.
            $tenant = Tenant::withTrashed()->where('optinexus_tenant_id', $nexusTenantId)->first();
            if (! $tenant) {
                throw $e;
            }

            return $this->active($tenant);
        }
    }

    private function active(Tenant $tenant): Tenant
    {
        if ($tenant->trashed() || ! $tenant->isActive()) {
            throw new SsoException('tenant_inactive');
        }

        return $tenant;
    }
}
