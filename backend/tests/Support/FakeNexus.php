<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A fake OptiNexus built from the documented contract (OptiNexus docs/ecosystem/GOVERNANCE_CONTRACTS.md): the same JSON
 * envelopes, field names and error codes, with state kept in memory. It answers the service-account token endpoint, the bulk
 * effective-permissions endpoint, the product-context endpoint, the commercial context and the delegation API. Failure modes
 * (outage, rate limit, server error, unbound service account) are switches. Every request is recorded.
 */
class FakeNexus
{
    public const BASE = 'http://nexus.test';

    public const APP = 'optifleet';

    /** @var array<string, array{permissions: array<string, string>, allowed: bool, deny_reason: ?string, session_reason: ?string}> */
    public array $users = [];

    /** @var array<string, array{status: string, capabilities: array<int, string>, applications: array<int, string>, subscriptions: array<int, string>}> */
    public array $tenants = [];

    /** @var array<int, array{method: string, path: string, query: array, body: array, scope: ?string}> */
    public array $calls = [];

    /** up | down | rate_limited | server_error | unbound | tenant_mismatch */
    public string $mode = 'up';

    /** Products every allowed user sees in product-context besides the current one. */
    public array $products = [];

    public int $ttl = 300;

    /** @var array<string, array<string, mixed>> delegation state per nexus tenant */
    public array $delegation = [];

    public int $tokensIssued = 0;

    public ?string $failTokenWith = null;

    /** The API Gateway part (vehicle directory, odometer feed). */
    public FakeNexusGateway $gateway;

    public function __construct()
    {
        $this->gateway = new FakeNexusGateway;
    }

    public function install(): self
    {
        config([
            'optinexus.enabled' => true,
            'optinexus.base_url' => self::BASE,
            'optinexus.gateway.client_id' => 'fleet-service',
            'optinexus.gateway.client_secret' => 'not-a-real-secret',
        ]);
        Http::fake([self::BASE.'/*' => fn (Request $request) => $this->respond($request)]);

        return $this;
    }

    // ---------------------------------------------------------------- state

    /**
     * @param  array<int, string>|null  $applications  application codes the tenant is entitled to (default: this application only)
     * @param  array<int, string>|null  $subscriptions  statuses of the live-like subscriptions it has (default: one ACTIVE)
     */
    public function tenant(string $nexusTenantId, array $capabilities = [], string $status = 'ACTIVE', ?array $applications = null, ?array $subscriptions = null): self
    {
        $this->tenants[$nexusTenantId] = [
            'status' => $status, 'capabilities' => $capabilities,
            'applications' => $applications ?? [self::APP], 'subscriptions' => $subscriptions ?? ['ACTIVE'],
        ];

        return $this;
    }

    /** @param  array<int, string>  $localPermissionNames */
    public function grant(string $nexusTenantId, string $subject, array $localPermissionNames, string $scope = 'APPLICATION'): self
    {
        $this->users[$nexusTenantId.'|'.$subject] = [
            'permissions' => collect($localPermissionNames)->mapWithKeys(fn (string $n) => [self::APP.'.'.$n => $scope])->all(),
            'allowed' => true, 'deny_reason' => null, 'session_reason' => null,
        ];

        return $this;
    }

    /** The person loses the application altogether (product-context says allowed=false with this reason). */
    public function withdraw(string $nexusTenantId, string $subject, string $reason = 'application_access_revoked'): self
    {
        $this->users[$nexusTenantId.'|'.$subject] = ['permissions' => [], 'allowed' => false, 'deny_reason' => 'PERMISSION_NOT_GRANTED', 'session_reason' => $reason];

        return $this;
    }

    public function callsTo(string $needle): int
    {
        return count(array_filter($this->calls, fn (array $c) => str_contains($c['path'], $needle)));
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    // ------------------------------------------------------------- dispatch

    private function respond(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $body = $request->isJson() ? (array) $request->data() : (array) $request->data();
        $scope = $path === '/api/v1/oauth/token' ? ($body['scope'] ?? null) : null;
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'query' => $query, 'body' => $body, 'scope' => $scope];

        if ($this->mode === 'down') {
            throw new ConnectionException('cURL error 7: Failed to connect to nexus.test');
        }

        if ($path === '/api/v1/oauth/token') {
            if ($this->failTokenWith !== null) {
                return Http::response(['error' => $this->failTokenWith], 401);
            }
            $this->tokensIssued++;

            return Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'token-'.$this->tokensIssued]);
        }

        if ($this->mode === 'rate_limited') {
            return Http::response(['success' => false, 'error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.', 'details' => ['retry_after_seconds' => 30]]], 429, ['Retry-After' => '30']);
        }
        if ($this->mode === 'server_error') {
            return Http::response('boom', 500);
        }
        if ($this->mode === 'unbound') {
            return $this->fail('SERVICE_ACCOUNT_NOT_BOUND', 'This service account is not bound to an application.', 403);
        }
        if ($this->mode === 'tenant_mismatch') {
            return $this->fail('TENANT_MISMATCH', 'The service account is bound to another tenant.', 403);
        }

        if ($path === '/.well-known/openid-configuration') {
            return Http::response([
                'issuer' => self::BASE, 'authorization_endpoint' => self::BASE.'/oidc/authorize', 'token_endpoint' => self::BASE.'/api/oidc/token',
                'userinfo_endpoint' => self::BASE.'/api/oidc/userinfo', 'jwks_uri' => self::BASE.'/oidc/jwks', 'end_session_endpoint' => self::BASE.'/oidc/logout',
            ]);
        }

        return match (true) {
            $path === '/api/v1/authorization/effective-permissions' => $this->effectivePermissions($body),
            $path === '/api/v1/authorization/product-context' => $this->productContext($body),
            (bool) preg_match('#^/api/v1/tenants/([^/]+)/commercial-context$#', $path, $m) => $this->commercialContext($m[1]),
            str_starts_with($path, '/api/gateway/v1/') => $this->gateway->handle($request, $path, $query, $body),
            str_starts_with($path, '/api/v1/delegation/tenants/') => (new FakeNexusDelegation($this))->handle($request, $path, $query, $body),
            default => $this->fail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404),
        };
    }

    private function effectivePermissions(array $body)
    {
        $user = $this->users[($body['tenant_id'] ?? '').'|'.($body['user_id'] ?? '')] ?? null;
        $tenant = $this->tenants[$body['tenant_id'] ?? ''] ?? null;

        $allowed = $user !== null && $tenant !== null && $tenant['status'] === 'ACTIVE' && $user['allowed'] && $user['permissions'] !== [];
        $reason = match (true) {
            $user === null => 'PERMISSION_NOT_GRANTED',
            $tenant === null => 'TENANT_NOT_FOUND',
            $tenant['status'] !== 'ACTIVE' => 'TENANT_NOT_ACTIVE',
            $user['permissions'] === [] || ! $user['allowed'] => 'PERMISSION_NOT_GRANTED',
            default => null,
        };

        return $this->ok([
            'schema_version' => '1.0',
            'user_id' => $body['user_id'] ?? null,
            'tenant_id' => $body['tenant_id'] ?? null,
            'application_code' => self::APP,
            'allowed' => $allowed,
            'deny_reason' => $reason,
            'permissions' => $allowed ? collect($user['permissions'])->map(fn (string $scope, string $key) => ['permission' => $key, 'scope' => $scope])->values()->all() : [],
            'evaluated_at' => now()->toIso8601String(),
            'ttl_seconds' => $this->ttl,
        ]);
    }

    private function productContext(array $body)
    {
        $user = $this->users[($body['tenant_id'] ?? '').'|'.($body['user_id'] ?? '')] ?? null;
        $tenant = $this->tenants[$body['tenant_id'] ?? ''] ?? null;

        $deny = match (true) {
            $user === null => 'application_access_revoked',
            $tenant === null => 'tenant_not_found',
            $tenant['status'] !== 'ACTIVE' => 'tenant_'.strtolower($tenant['status']),
            $user['session_reason'] !== null => $user['session_reason'],
            default => null,
        };

        return $this->ok([
            'schema_version' => '1.0',
            'user_id' => $body['user_id'] ?? null,
            'tenant' => $deny === null ? ['id' => $body['tenant_id'], 'code' => 'ACME', 'name' => 'Acme'] : null,
            'application_code' => self::APP,
            'allowed' => $deny === null,
            'deny_reason' => $deny,
            'products' => $deny === null ? array_merge([['code' => self::APP, 'name' => 'OptiFleet', 'launch_url' => 'http://fleet.test/sso', 'current' => true]], $this->products) : [],
            'evaluated_at' => now()->toIso8601String(),
            'ttl_seconds' => $this->ttl,
        ]);
    }

    private function commercialContext(string $nexusTenantId)
    {
        $tenant = $this->tenants[$nexusTenantId] ?? null;
        if (! $tenant) {
            return $this->fail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404);
        }

        return $this->ok([
            'tenant_id' => $nexusTenantId,
            'tenant_status' => $tenant['status'],
            'resource_limits' => $tenant['resource_limits'] ?? [],
            'subscriptions' => array_map(fn (string $status) => ['id' => self::uuid(), 'tenant_id' => $nexusTenantId, 'status' => $status], $tenant['subscriptions']),
            'applications_enabled' => $tenant['applications'],
            'effective_entitlements' => array_merge(
                array_map(fn (string $code) => ['entitlement_type' => 'APPLICATION', 'entitlement_key' => $code, 'value' => true, 'source' => 'PLAN'], $tenant['applications']),
                array_map(fn (string $code) => ['entitlement_type' => 'CAPABILITY', 'entitlement_key' => $code, 'value' => true, 'source' => 'PLAN'], $tenant['capabilities']),
                [['entitlement_type' => 'LIMIT', 'entitlement_key' => 'max_vehicles', 'value' => 100, 'source' => 'PLAN']],
            ),
        ]);
    }

    // ------------------------------------------------------------- envelope

    public function ok(mixed $data, int $status = 200, array $meta = [])
    {
        return Http::response(['success' => true, 'data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    public function fail(string $code, string $message, int $status, mixed $details = null, array $headers = [])
    {
        return Http::response(['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status, $headers);
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }
}
