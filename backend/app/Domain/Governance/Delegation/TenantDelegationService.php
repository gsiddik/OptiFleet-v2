<?php

namespace App\Domain\Governance\Delegation;

use App\Domain\AccessControl\Services\PermissionCatalog;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Governance\CentralGovernance;
use App\Domain\Governance\GovernanceCatalog;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Users and roles of a centrally governed tenant, managed from OptiFleet through the OptiNexus delegation API (audit R08/A25).
 * OptiNexus keeps the identities, the roles and the assignments; OptiFleet keeps no copy: it renders what OptiNexus answers
 * (read-through, nothing cached beyond one request), sends changes with the signed-in person as the acting user, writes its own
 * audit entry for every change and every refusal, and drops the cached access answers of the people a change affects so it is
 * in force at once rather than within one TTL.
 *
 * What OptiNexus decides, and OptiFleet therefore never re-implements: who is an Admin Tenant, which permissions are delegable,
 * which roles are assignable, and every protection (Admin Tenant, self revocation, roles in use). OptiFleet only adds the
 * permission gate of its own routes (user.* / role.*) in front.
 */
class TenantDelegationService
{
    public function __construct(
        private readonly NexusDelegationClient $client,
        private readonly CentralGovernance $central,
        private readonly PermissionCatalog $permissions,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{nexus_tenant: string, subject: string}
     *
     * @throws DelegationException when the tenant is not governed centrally or the person has no OptiNexus identity
     */
    public function context(User $actor, string $tenantId): array
    {
        $link = $this->central->link($tenantId);
        if (! $link || $link['mode'] !== 'CENTRAL' || ! $link['nexus_tenant_id']) {
            throw DelegationErrors::notCentral();
        }
        if (! $actor->optinexus_subject) {
            throw DelegationErrors::identityNotLinked();
        }

        return ['nexus_tenant' => $link['nexus_tenant_id'], 'subject' => $actor->optinexus_subject];
    }

    // ------------------------------------------------------------------ reads

    /**
     * The delegable permissions grouped Module -> Feature -> Action (the labels of OptiFleet's own role editor) and the roles
     * that can be assigned.
     *
     * @return array{modules: array<int, array<string, mixed>>, roles: array<int, array<string, mixed>>, delegable_count: int}
     */
    public function catalog(User $actor, string $tenantId): array
    {
        $ctx = $this->context($actor, $tenantId);
        $response = $this->read(fn () => $this->client->catalog($ctx['nexus_tenant'], $ctx['subject']), 'catalog', $actor, $tenantId);

        $data = $response['data'];
        $this->expectArray($data, ['delegable_permissions', 'roles'], $response['correlation_id']);

        $local = $this->permissions->forScope('tenant')->keyBy('name');
        $modules = [];
        foreach ($data['delegable_permissions'] as $row) {
            if (! is_array($row) || ! is_string($row['permission'] ?? null)) {
                continue;
            }
            $name = GovernanceCatalog::localName($row['permission']);
            $meta = $name !== null ? $local->get($name) : null;

            $moduleCode = $meta['module'] ?? 'OTHER';
            $featureCode = $meta['feature'] ?? 'other';
            $modules[$moduleCode]['code'] = $moduleCode;
            $modules[$moduleCode]['name'] = $meta['module_name'] ?? 'Other';
            $modules[$moduleCode]['features'][$featureCode]['code'] = $featureCode;
            $modules[$moduleCode]['features'][$featureCode]['name'] = $meta['feature_name'] ?? 'Other';
            $modules[$moduleCode]['features'][$featureCode]['permissions'][] = [
                'key' => $row['permission'],
                'name' => $name ?? $row['permission'],
                'action_name' => $meta['action_name'] ?? ($row['name'] ?? $row['permission']),
                'description' => $meta['description'] ?? ($row['description'] ?? null),
                'capability' => $row['capability'] ?? null,
            ];
        }

        $tree = collect($modules)->map(fn (array $m) => [
            'code' => $m['code'], 'name' => $m['name'],
            'features' => collect($m['features'])->sortBy('name')->values()->map(fn (array $f) => [
                'code' => $f['code'], 'name' => $f['name'], 'permissions' => collect($f['permissions'])->sortBy('key')->values()->all(),
            ])->all(),
        ])->sortBy('name')->values()->all();

        return [
            'modules' => $tree,
            'roles' => collect($data['roles'])->filter(fn ($v) => is_array($v))->map(fn (array $r) => $this->roleView($r))->values()->all(),
            'delegable_count' => count($data['delegable_permissions']),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function users(User $actor, string $tenantId, ?string $search, int $perPage): array
    {
        $ctx = $this->context($actor, $tenantId);
        $response = $this->read(fn () => $this->client->users($ctx['nexus_tenant'], $ctx['subject'], $search, max(1, min(100, $perPage))), 'users', $actor, $tenantId);
        if (! is_array($response['data'])) {
            throw $this->unexpected($response['correlation_id']);
        }

        $rows = collect($response['data'])->filter(fn ($v) => is_array($v))->values();
        $localByNexus = $this->localMembers($tenantId, $rows->pluck('id')->filter(fn ($v) => is_string($v))->all());

        return [
            'items' => $rows->map(fn (array $u) => $this->userView($u, $localByNexus[$u['id'] ?? ''] ?? null))->all(),
            'pagination' => is_array($response['meta']['pagination'] ?? null) ? $response['meta']['pagination'] : ['current_page' => 1, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => 1],
        ];
    }

    // ---------------------------------------------------------------- changes

    /**
     * @param  array{email: string, name: string, role_ids?: array<int, string>}  $input
     * @return array<string, mixed>
     */
    public function addUser(User $actor, string $tenantId, array $input): array
    {
        $ctx = $this->context($actor, $tenantId);
        $payload = ['email' => $input['email'], 'name' => $input['name']] + (empty($input['role_ids']) ? [] : ['role_ids' => array_values($input['role_ids'])]);
        $response = $this->change(fn () => $this->client->addUser($ctx['nexus_tenant'], $ctx['subject'], $payload), 'user_add', $actor, $tenantId, null);

        $data = $response['data'];
        $this->expectArray($data, ['id', 'outcome'], $response['correlation_id']);
        $this->recordAudit($actor, $tenantId, 'delegation.user_'.$data['outcome'], (string) $data['id'], [
            'email' => $data['email'] ?? $input['email'], 'role_ids' => $input['role_ids'] ?? [], 'outcome' => $data['outcome'], 'correlation_id' => $response['correlation_id'],
        ]);
        $this->forgetSubject($data['id']);

        return ['outcome' => $data['outcome']] + $this->userView($data, null);
    }

    /**
     * @param  array<int, string>  $roleIds
     * @return array<string, mixed>
     */
    public function setUserRoles(User $actor, string $tenantId, string $nexusUserId, array $roleIds): array
    {
        $ctx = $this->context($actor, $tenantId);
        $response = $this->change(fn () => $this->client->setUserRoles($ctx['nexus_tenant'], $ctx['subject'], $nexusUserId, $roleIds), 'user_roles', $actor, $tenantId, $nexusUserId);
        $this->expectArray($response['data'], ['id'], $response['correlation_id']);

        $this->recordAudit($actor, $tenantId, 'delegation.user_roles_set', $nexusUserId, ['role_ids' => array_values($roleIds), 'correlation_id' => $response['correlation_id']]);
        $this->forgetSubject($nexusUserId);

        return $this->userView($response['data'], $this->localMembers($tenantId, [$nexusUserId])[$nexusUserId] ?? null);
    }

    public function revokeUser(User $actor, string $tenantId, string $nexusUserId): void
    {
        $ctx = $this->context($actor, $tenantId);
        $response = $this->change(fn () => $this->client->revokeUser($ctx['nexus_tenant'], $ctx['subject'], $nexusUserId), 'user_revoke', $actor, $tenantId, $nexusUserId);

        $this->recordAudit($actor, $tenantId, 'delegation.user_access_revoked', $nexusUserId, ['correlation_id' => $response['correlation_id']]);
        $this->forgetSubject($nexusUserId);
    }

    /**
     * @param  array{name: string, description?: ?string, permissions: array<int, string>}  $input
     * @return array<string, mixed>
     */
    public function createRole(User $actor, string $tenantId, array $input): array
    {
        $ctx = $this->context($actor, $tenantId);
        $payload = ['name' => $input['name'], 'permissions' => array_values($input['permissions'])] + (($input['description'] ?? null) !== null ? ['description' => $input['description']] : []);
        $response = $this->change(fn () => $this->client->createRole($ctx['nexus_tenant'], $ctx['subject'], $payload), 'role_create', $actor, $tenantId, null);
        $this->expectArray($response['data'], ['id', 'name', 'permissions'], $response['correlation_id']);

        $this->recordAudit($actor, $tenantId, 'delegation.role_created', (string) $response['data']['id'], [
            'name' => $response['data']['name'], 'permission_count' => count($response['data']['permissions']), 'correlation_id' => $response['correlation_id'],
        ]);

        return $this->roleView($response['data']);
    }

    /**
     * @param  array<string, mixed>  $changes  name, description and/or permissions
     * @return array<string, mixed>
     */
    public function updateRole(User $actor, string $tenantId, string $roleId, array $changes): array
    {
        $ctx = $this->context($actor, $tenantId);
        $response = $this->change(fn () => $this->client->updateRole($ctx['nexus_tenant'], $ctx['subject'], $roleId, $changes), 'role_update', $actor, $tenantId, $roleId);
        $this->expectArray($response['data'], ['id', 'name', 'permissions'], $response['correlation_id']);

        $this->recordAudit($actor, $tenantId, 'delegation.role_updated', $roleId, [
            'fields' => array_keys($changes), 'permission_count' => count($response['data']['permissions']), 'correlation_id' => $response['correlation_id'],
        ]);
        // Everybody holding the role now has other permissions: do not wait for the cached answers to expire.
        $this->forgetTenantMembers($tenantId);

        return $this->roleView($response['data']);
    }

    public function deleteRole(User $actor, string $tenantId, string $roleId): void
    {
        $ctx = $this->context($actor, $tenantId);
        $response = $this->change(fn () => $this->client->deleteRole($ctx['nexus_tenant'], $ctx['subject'], $roleId), 'role_delete', $actor, $tenantId, $roleId);

        $this->recordAudit($actor, $tenantId, 'delegation.role_deleted', $roleId, ['correlation_id' => $response['correlation_id']]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function read(callable $call, string $operation, User $actor, string $tenantId)
    {
        try {
            return $call();
        } catch (DelegationException $e) {
            $this->noteFailure($e, $operation, $actor, $tenantId, null, audit: $e->errorCode === 'ACTOR_NOT_TENANT_ADMIN');

            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function change(callable $call, string $operation, User $actor, string $tenantId, ?string $target)
    {
        try {
            return $call();
        } catch (DelegationException $e) {
            $this->noteFailure($e, $operation, $actor, $tenantId, $target, audit: true);

            throw $e;
        }
    }

    /** A refusal by OptiNexus is an authorization event worth keeping; an outage is only logged (nothing was decided). */
    private function noteFailure(DelegationException $e, string $operation, User $actor, string $tenantId, ?string $target, bool $audit): void
    {
        $unavailable = $e->errorCode === DelegationErrors::UNAVAILABLE[0] || $e->httpStatus >= 500;
        Log::warning('Delegation call did not succeed.', ['operation' => $operation, 'code' => $e->errorCode, 'correlation_id' => $e->correlationId, 'tenant_id' => $tenantId]);

        if ($audit && ! $unavailable) {
            $this->recordAudit($actor, $tenantId, 'delegation.refused', $target ?? $tenantId, ['operation' => $operation, 'code' => $e->errorCode, 'correlation_id' => $e->correlationId]);
        }
    }

    /** @param  array<string, mixed>  $values */
    private function recordAudit(User $actor, string $tenantId, string $action, string $resourceId, array $values): void
    {
        $this->audit->log('delegation', $resourceId, $action, null, $values + ['actor_subject' => $actor->optinexus_subject], $tenantId);
    }

    /**
     * @param  array<int, string>  $nexusUserIds
     * @return array<string, string> OptiNexus user id => id of the local tenant membership (used for the local data scope)
     */
    private function localMembers(string $tenantId, array $nexusUserIds): array
    {
        if ($nexusUserIds === []) {
            return [];
        }

        return TenantUser::query()->where('tenant_users.tenant_id', $tenantId)->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->whereIn('users.optinexus_subject', $nexusUserIds)->pluck('tenant_users.id', 'users.optinexus_subject')->all();
    }

    private function forgetSubject(string $nexusUserId): void
    {
        User::query()->where('optinexus_subject', $nexusUserId)->get()->each(fn (User $u) => $this->central->forgetUser($u));
    }

    private function forgetTenantMembers(string $tenantId): void
    {
        $this->central->forgetTenantUsers($tenantId);
    }

    /**
     * @param  array<string, mixed>  $u
     * @return array<string, mixed>
     */
    private function userView(array $u, ?string $localMemberId): array
    {
        return [
            'id' => (string) ($u['id'] ?? ''),
            'name' => $u['name'] ?? null,
            'email' => $u['email'] ?? null,
            'status' => $u['status'] ?? null,
            'membership_status' => $u['membership_status'] ?? null,
            'is_tenant_admin' => (bool) ($u['is_tenant_admin'] ?? false),
            'application_access' => $u['application_access'] ?? null,
            'roles' => collect($u['roles'] ?? [])->filter(fn ($v) => is_array($v))->map(fn (array $r) => $this->roleView($r))->values()->all(),
            // The local membership, if the person already signed in here: needed for the local data scope only.
            'local_member_id' => $localMemberId,
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function roleView(array $r): array
    {
        return [
            'id' => (string) ($r['id'] ?? ''),
            'code' => $r['code'] ?? null,
            'name' => $r['name'] ?? null,
            'description' => $r['description'] ?? null,
            'template' => (bool) ($r['template'] ?? false),
            'editable' => (bool) ($r['editable'] ?? false),
            'permissions' => array_values(array_filter((array) ($r['permissions'] ?? []), 'is_string')),
        ];
    }

    /**
     * A success answer that does not have the documented shape is a contract problem, not a success.
     *
     * @param  array<int, string>  $keys
     *
     * @throws DelegationException
     */
    private function expectArray(mixed $data, array $keys, string $correlationId): void
    {
        if (! is_array($data)) {
            throw $this->unexpected($correlationId);
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                throw $this->unexpected($correlationId);
            }
        }
    }

    private function unexpected(string $correlationId): DelegationException
    {
        [$code, $status, $key] = DelegationErrors::UNEXPECTED;

        return new DelegationException($code, $status, $key, null, $correlationId);
    }
}
