<?php

namespace App\Domain\Governance\Delegation;

use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Governance\NexusApiClient;
use App\Domain\Governance\NexusTokenProvider;
use Illuminate\Support\Str;

/**
 * The OptiNexus tenant delegation API (contract: GOVERNANCE_CONTRACTS.md section 2) as one thin client. Every call carries the
 * OptiNexus tenant (the path) and the acting user (the signed-in person's OptiNexus subject, never a value from a request),
 * uses the service account with the least scope (`tenant.delegate`) and the bounded timeouts of NexusApiClient. A call that
 * changes something is never retried after a lost connection (it may have been applied): the caller is told the outcome is unknown.
 */
class NexusDelegationClient
{
    public function __construct(private readonly NexusApiClient $api) {}

    /** @return array{data: mixed, meta: mixed, correlation_id: string, status: int} */
    public function catalog(string $nexusTenantId, string $actingUserId): array
    {
        return $this->call('GET', $nexusTenantId, '/catalog', $actingUserId);
    }

    /** @return array{data: mixed, meta: mixed, correlation_id: string, status: int} */
    public function users(string $nexusTenantId, string $actingUserId, ?string $search, int $perPage): array
    {
        return $this->call('GET', $nexusTenantId, '/users', $actingUserId, array_filter(['search' => $search, 'per_page' => $perPage], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param  array{email: string, name: string, role_ids?: array<int, string>}  $user
     * @return array{data: mixed, meta: mixed, correlation_id: string, status: int}
     */
    public function addUser(string $nexusTenantId, string $actingUserId, array $user): array
    {
        return $this->call('POST', $nexusTenantId, '/users', $actingUserId, $user);
    }

    /**
     * @param  array<int, string>  $roleIds
     * @return array{data: mixed, meta: mixed, correlation_id: string, status: int}
     */
    public function setUserRoles(string $nexusTenantId, string $actingUserId, string $userId, array $roleIds): array
    {
        return $this->call('PUT', $nexusTenantId, '/users/'.$this->id($userId).'/roles', $actingUserId, ['role_ids' => array_values($roleIds)]);
    }

    /** @return array{data: mixed, meta: mixed, correlation_id: string, status: int} */
    public function revokeUser(string $nexusTenantId, string $actingUserId, string $userId): array
    {
        return $this->call('DELETE', $nexusTenantId, '/users/'.$this->id($userId), $actingUserId);
    }

    /**
     * @param  array{name: string, description?: ?string, permissions: array<int, string>}  $role
     * @return array{data: mixed, meta: mixed, correlation_id: string, status: int}
     */
    public function createRole(string $nexusTenantId, string $actingUserId, array $role): array
    {
        return $this->call('POST', $nexusTenantId, '/roles', $actingUserId, $role);
    }

    /**
     * @param  array<string, mixed>  $changes  name, description and/or permissions
     * @return array{data: mixed, meta: mixed, correlation_id: string, status: int}
     */
    public function updateRole(string $nexusTenantId, string $actingUserId, string $roleId, array $changes): array
    {
        return $this->call('PUT', $nexusTenantId, '/roles/'.$this->id($roleId), $actingUserId, $changes);
    }

    /** @return array{data: mixed, meta: mixed, correlation_id: string, status: int} */
    public function deleteRole(string $nexusTenantId, string $actingUserId, string $roleId): array
    {
        return $this->call('DELETE', $nexusTenantId, '/roles/'.$this->id($roleId), $actingUserId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: mixed, meta: mixed, correlation_id: string, status: int}
     *
     * @throws DelegationException
     */
    private function call(string $method, string $nexusTenantId, string $path, string $actingUserId, array $payload = []): array
    {
        $mutation = $method !== 'GET';

        try {
            $response = $this->api->send(NexusTokenProvider::SCOPES_DELEGATION, $method, 'delegation/tenants/'.$this->id($nexusTenantId).$path, array_merge($payload, ['acting_user_id' => $actingUserId]));
        } catch (GovernanceUnavailableException $e) {
            throw DelegationErrors::unavailable((string) Str::uuid(), $mutation);
        }

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return ['data' => $response['data'], 'meta' => $response['meta'], 'correlation_id' => $response['correlation_id'], 'status' => $response['status']];
        }

        throw DelegationErrors::fromResponse($response, $mutation);
    }

    /** Ids go into a URL path: only a UUID is ever accepted, so nothing from a request can reshape the path. */
    private function id(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw DelegationErrors::fromResponse(['status' => 404, 'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => null, 'details' => null], 'correlation_id' => (string) Str::uuid()], false);
        }

        return strtolower($id);
    }
}
