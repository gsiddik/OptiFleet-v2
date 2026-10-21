<?php

namespace App\Domain\Governance;

use App\Domain\Governance\Exceptions\GovernanceContractException;

/**
 * The governance reads OptiFleet needs from OptiNexus (contract: OptiNexus docs/ecosystem/GOVERNANCE_CONTRACTS.md):
 *  - POST /authorization/effective-permissions  (bulk, schema 1.0, one request per user and tenant, not per permission)
 *  - POST /authorization/product-context        (switcher + session revalidation, schema 1.0)
 *  - GET  /tenants/{tenant}/commercial-context  (module/feature entitlement: capability entitlements of the tenant)
 * The application is the service account's, never taken from a payload. Answers are validated against the documented shape
 * here; anything else fails closed through GovernanceContractException.
 */
class NexusGovernanceClient
{
    public const SCHEMA_MAJOR = '1';

    public function __construct(private readonly NexusApiClient $api) {}

    /**
     * @return array{allowed: bool, deny_reason: ?string, permissions: array<string, string>, ttl_seconds: int}
     */
    public function effectivePermissions(string $nexusUserId, string $nexusTenantId): array
    {
        $r = $this->api->send(NexusTokenProvider::SCOPES_GOVERNANCE, 'POST', 'authorization/effective-permissions', [
            'user_id' => $nexusUserId,
            'tenant_id' => $nexusTenantId,
            'application_code' => config('optinexus.governance.application_code'),
        ]);
        $data = $this->expectData($r, 'effective-permissions');

        $permissions = [];
        foreach ((array) ($data['permissions'] ?? []) as $row) {
            if (is_array($row) && is_string($row['permission'] ?? null) && $row['permission'] !== '') {
                $permissions[$row['permission']] = (string) ($row['scope'] ?? 'APPLICATION');
            }
        }

        return [
            'allowed' => (bool) ($data['allowed'] ?? false),
            'deny_reason' => isset($data['deny_reason']) ? (string) $data['deny_reason'] : null,
            'permissions' => $permissions,
            'ttl_seconds' => $this->ttl($data),
        ];
    }

    /**
     * @return array{allowed: bool, deny_reason: ?string, tenant: ?array{id: string, code: ?string, name: ?string}, products: array<int, array{code: string, name: string, launch_url: ?string, current: bool}>, ttl_seconds: int}
     */
    public function productContext(string $nexusUserId, string $nexusTenantId): array
    {
        $r = $this->api->send(NexusTokenProvider::SCOPES_GOVERNANCE, 'POST', 'authorization/product-context', [
            'user_id' => $nexusUserId,
            'tenant_id' => $nexusTenantId,
        ]);
        $data = $this->expectData($r, 'product-context');

        $products = [];
        foreach ((array) ($data['products'] ?? []) as $row) {
            if (is_array($row) && is_string($row['code'] ?? null)) {
                $products[] = [
                    'code' => $row['code'],
                    'name' => (string) ($row['name'] ?? $row['code']),
                    'launch_url' => isset($row['launch_url']) ? (string) $row['launch_url'] : null,
                    'current' => (bool) ($row['current'] ?? false),
                ];
            }
        }

        $tenant = is_array($data['tenant'] ?? null) && is_string($data['tenant']['id'] ?? null)
            ? ['id' => $data['tenant']['id'], 'code' => $data['tenant']['code'] ?? null, 'name' => $data['tenant']['name'] ?? null] : null;

        return [
            'allowed' => (bool) ($data['allowed'] ?? false),
            'deny_reason' => isset($data['deny_reason']) ? (string) $data['deny_reason'] : null,
            'tenant' => $tenant,
            'products' => $products,
            'ttl_seconds' => $this->ttl($data),
        ];
    }

    /**
     * Capability (module/feature) entitlements of one tenant.
     *
     * `live_subscriptions` counts the subscriptions OptiNexus lists as commercially live (it only lists live-like ones) whose status is
     * in `optinexus.feed.live_subscription_statuses`. The context does not say which subscription covers which application, so the
     * applications come from the APPLICATION entitlements (`applications_enabled`).
     *
     * @return array{tenant_status: ?string, capabilities: array<int, string>, applications: array<int, string>, live_subscriptions: int}
     */
    public function commercialContext(string $nexusTenantId): array
    {
        $r = $this->api->send(NexusTokenProvider::SCOPES_GOVERNANCE, 'GET', 'tenants/'.$nexusTenantId.'/commercial-context');

        if ($r['status'] !== 200 || ! is_array($r['data'])) {
            throw $this->contract('commercial-context', $r);
        }

        $capabilities = [];
        foreach ((array) ($r['data']['effective_entitlements'] ?? []) as $row) {
            if (is_array($row) && ($row['entitlement_type'] ?? null) === 'CAPABILITY' && ($row['value'] ?? null) === true && is_string($row['entitlement_key'] ?? null)) {
                $capabilities[] = $row['entitlement_key'];
            }
        }

        $resourceLimits = null;
        if (array_key_exists('resource_limits', $r['data'])) {
            if (! is_array($r['data']['resource_limits'])) {
                throw new GovernanceContractException('OptiNexus resource_limits must be an object.');
            }
            $resourceLimits = [];
            foreach ($r['data']['resource_limits'] as $key => $value) {
                if ($value === 'unlimited' || $value === null) {
                    $resourceLimits[$key] = null;
                } elseif (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= PHP_INT_MAX) {
                    $resourceLimits[$key] = (int) floor((float) $value);
                } else {
                    throw new GovernanceContractException('OptiNexus returned an invalid resource limit.');
                }
            }
        }

        $live = array_map('strtoupper', (array) config('optinexus.feed.live_subscription_statuses'));
        $liveSubscriptions = count(array_filter((array) ($r['data']['subscriptions'] ?? []), fn ($row) => is_array($row) && in_array(strtoupper((string) ($row['status'] ?? '')), $live, true)));

        return [
            'tenant_status' => isset($r['data']['tenant_status']) ? (string) $r['data']['tenant_status'] : null,
            'capabilities' => array_values(array_unique($capabilities)),
            'applications' => array_values(array_filter((array) ($r['data']['applications_enabled'] ?? []), 'is_string')),
            'live_subscriptions' => $liveSubscriptions,
            'resource_limits' => $resourceLimits,
        ];
    }

    /** @return array<string, mixed> */
    private function expectData(array $r, string $what): array
    {
        if ($r['status'] !== 200 || ! is_array($r['data'])) {
            throw $this->contract($what, $r);
        }

        $schema = (string) ($r['data']['schema_version'] ?? '');
        if (! str_starts_with($schema, self::SCHEMA_MAJOR.'.') && $schema !== self::SCHEMA_MAJOR) {
            throw new GovernanceContractException("OptiNexus answered {$what} with an unsupported schema_version [{$schema}].", null, $r['status']);
        }

        return $r['data'];
    }

    private function ttl(array $data): int
    {
        $ours = (int) config('optinexus.governance.ttl_seconds');
        $theirs = (int) ($data['ttl_seconds'] ?? 0);

        // Never longer than what OptiNexus allows, never longer than our own ceiling (300 s); an absent or invalid value means our ceiling.
        return $theirs > 0 ? min($theirs, $ours) : $ours;
    }

    private function contract(string $what, array $r): GovernanceContractException
    {
        $code = $r['error']['code'] ?? null;

        return new GovernanceContractException("OptiNexus refused {$what}".($code ? " [{$code}]" : '')." (HTTP {$r['status']}).", $code, $r['status']);
    }
}
