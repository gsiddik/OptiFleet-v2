<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;

/**
 * The tenant delegation API of OptiNexus (contract: GOVERNANCE_CONTRACTS.md section 2) as an in-memory state machine for tests.
 * It enforces the documented rules, so a test of OptiFleet's screens and mapping meets the same refusals the real service gives:
 * the acting user must be an Admin Tenant, only the delegable set may be handed out, roles from other tenants are not
 * assignable, an Admin Tenant is protected, a user cannot remove their own access, identities are linked by e-mail not copied.
 *
 * State (per OptiNexus tenant id), seeded by FakeNexus::delegationTenant():
 *   admins: subjects of Admin Tenants; delegable: the explicit delegable catalog; roles; users; identities (e-mail => subject).
 */
class FakeNexusDelegation
{
    public function __construct(private readonly FakeNexus $nexus) {}

    public static function seed(FakeNexus $nexus, string $tenant, array $admins, array $delegable, string $status = 'ACTIVE', bool $subscribed = true): void
    {
        $nexus->delegation[$tenant] = [
            'status' => $status, 'subscribed' => $subscribed, 'admins' => $admins,
            'delegable' => $delegable, 'roles' => [], 'users' => [], 'identities' => [],
        ];
    }

    public function handle(Request $request, string $path, array $query, array $body)
    {
        $method = $request->method();
        if (! preg_match('#^/api/v1/delegation/tenants/([^/]+)(/.*)?$#', $path, $m)) {
            return $this->nexus->fail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404);
        }
        $tenant = $m[1];
        $rest = $m[2] ?? '';
        $input = $method === 'GET' || $method === 'DELETE' ? $query : $body;

        if (! isset($this->nexus->delegation[$tenant])) {
            return $this->nexus->fail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404);
        }
        if (! Str::isUuid((string) ($input['acting_user_id'] ?? ''))) {
            return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['acting_user_id' => ['The acting user id field is required.']]);
        }

        $state = &$this->nexus->delegation[$tenant];

        if ($state['status'] !== 'ACTIVE') {
            return $this->nexus->fail('TENANT_NOT_ACTIVE', 'The tenant is not active.', 403);
        }
        if (! $state['subscribed']) {
            return $this->nexus->fail('APPLICATION_NOT_SUBSCRIBED', 'The tenant has no active subscription to this application.', 403);
        }
        if (! in_array($input['acting_user_id'], $state['admins'], true)) {
            return $this->nexus->fail('ACTOR_NOT_TENANT_ADMIN', 'The acting user is not an Admin Tenant of this tenant for this application.', 403);
        }
        $actor = $input['acting_user_id'];

        return match (true) {
            $method === 'GET' && $rest === '/catalog' => $this->catalog($state, $tenant),
            $method === 'GET' && $rest === '/users' => $this->listUsers($state, $input),
            $method === 'POST' && $rest === '/users' => $this->addUser($state, $input),
            $method === 'PUT' && (bool) preg_match('#^/users/([^/]+)/roles$#', $rest, $u) => $this->setRoles($state, $u[1], $input),
            $method === 'DELETE' && (bool) preg_match('#^/users/([^/]+)$#', $rest, $u) => $this->revoke($state, $u[1], $actor),
            $method === 'POST' && $rest === '/roles' => $this->createRole($state, $input),
            $method === 'PUT' && (bool) preg_match('#^/roles/([^/]+)$#', $rest, $r) => $this->updateRole($state, $r[1], $input),
            $method === 'DELETE' && (bool) preg_match('#^/roles/([^/]+)$#', $rest, $r) => $this->deleteRole($state, $r[1]),
            default => $this->nexus->fail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404),
        };
    }

    private function delegableKeys(array $state): array
    {
        return array_column($state['delegable'], 'permission');
    }

    private function roleView(array $role): array
    {
        return ['id' => $role['id'], 'code' => $role['code'], 'name' => $role['name'], 'description' => $role['description'], 'template' => $role['template'],
            'editable' => $role['editable'], 'permissions' => $role['permissions']];
    }

    private function userView(array $state, array $user): array
    {
        return ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'status' => $user['status'], 'membership_status' => $user['membership_status'],
            'is_tenant_admin' => $user['is_tenant_admin'], 'application_access' => $user['application_access'],
            'roles' => array_values(array_map(fn (string $id) => $this->roleView($state['roles'][$id]), array_filter($user['roles'], fn ($id) => isset($state['roles'][$id]))))];
    }

    private function catalog(array $state, string $tenant)
    {
        $delegable = $this->delegableKeys($state);

        return $this->nexus->ok([
            'tenant_id' => $tenant, 'application_code' => FakeNexus::APP,
            'delegable_permissions' => $state['delegable'],
            'roles' => array_values(array_map(fn ($r) => $this->roleView($r), array_filter($state['roles'], fn ($r) => array_diff($r['permissions'], $delegable) === []))),
        ]);
    }

    private function listUsers(array $state, array $input)
    {
        $search = strtolower((string) ($input['search'] ?? ''));
        $items = array_values(array_filter($state['users'], fn ($u) => $search === '' || str_contains(strtolower($u['name'].' '.$u['email']), $search)));
        $per = max(1, min(100, (int) ($input['per_page'] ?? 25)));

        return $this->nexus->ok(array_map(fn ($u) => $this->userView($state, $u), array_slice($items, 0, $per)), 200, [
            'pagination' => ['current_page' => 1, 'per_page' => $per, 'total' => count($items), 'last_page' => max(1, (int) ceil(count($items) / $per))],
        ]);
    }

    private function resolveRoles(array $state, array $roleIds)
    {
        $delegable = $this->delegableKeys($state);
        $refused = array_values(array_filter($roleIds, fn ($id) => ! isset($state['roles'][$id]) || array_diff($state['roles'][$id]['permissions'], $delegable) !== []));

        return $refused;
    }

    private function addUser(array &$state, array $input)
    {
        foreach (['email', 'name'] as $field) {
            if (empty($input[$field])) {
                return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, [$field => ["The {$field} field is required."]]);
            }
        }
        if (! filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['email' => ['The email field must be a valid email address.']]);
        }
        $roleIds = array_values(array_unique($input['role_ids'] ?? []));
        if ($refused = $this->resolveRoles($state, $roleIds)) {
            return $this->nexus->fail('ROLE_NOT_ASSIGNABLE', 'A role is unknown, belongs to another tenant or application, or carries permissions that are not delegable.', 422, ['role_ids' => $refused]);
        }

        $email = strtolower(trim($input['email']));
        if (in_array($email, $state['operators'] ?? [], true)) {
            return $this->nexus->fail('IDENTITY_NOT_LINKABLE', 'This email belongs to an identity that cannot be added from a product.', 409);
        }

        $existingId = $state['identities'][$email] ?? null;
        $existing = $existingId ? ($state['users'][$existingId] ?? null) : null;
        if ($existing) {
            $outcome = $existing['application_access'] === 'ACTIVE' ? 'existing' : 'linked';
            $state['users'][$existingId]['application_access'] = 'ACTIVE';
            $user = &$state['users'][$existingId];
        } elseif ($existingId) {
            // The identity exists centrally (another tenant or product) and is linked here.
            $state['users'][$existingId] = ['id' => $existingId, 'name' => $input['name'], 'email' => $email, 'status' => 'ACTIVE', 'membership_status' => 'ACTIVE',
                'is_tenant_admin' => false, 'application_access' => 'ACTIVE', 'roles' => []];
            $outcome = 'linked';
            $user = &$state['users'][$existingId];
        } else {
            $id = FakeNexus::uuid();
            $state['identities'][$email] = $id;
            $state['users'][$id] = ['id' => $id, 'name' => $input['name'], 'email' => $email, 'status' => ! empty($input['initial_password']) ? 'ACTIVE' : 'INVITED',
                'membership_status' => 'ACTIVE', 'is_tenant_admin' => false, 'application_access' => 'ACTIVE', 'roles' => []];
            $outcome = 'created';
            $user = &$state['users'][$id];
        }

        if ($roleIds !== []) {
            $user['roles'] = $roleIds;
        }

        return $this->nexus->ok(['outcome' => $outcome] + $this->userView($state, $user), $outcome === 'created' ? 201 : 200);
    }

    private function target(array &$state, string $userId)
    {
        $user = $state['users'][$userId] ?? null;
        if (! $user) {
            return $this->nexus->fail('USER_NOT_IN_TENANT', 'The user does not belong to this tenant and application.', 404);
        }
        if ($user['is_tenant_admin']) {
            return $this->nexus->fail('TENANT_ADMIN_PROTECTED', 'An Admin Tenant can only be changed from the ecosystem portal.', 409);
        }

        return null;
    }

    private function setRoles(array &$state, string $userId, array $input)
    {
        if (! array_key_exists('role_ids', $input) || ! is_array($input['role_ids'])) {
            return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['role_ids' => ['The role ids field must be present.']]);
        }
        if ($denied = $this->target($state, $userId)) {
            return $denied;
        }
        $roleIds = array_values(array_unique($input['role_ids']));
        if ($refused = $this->resolveRoles($state, $roleIds)) {
            return $this->nexus->fail('ROLE_NOT_ASSIGNABLE', 'A role is unknown, belongs to another tenant or application, or carries permissions that are not delegable.', 422, ['role_ids' => $refused]);
        }
        $state['users'][$userId]['roles'] = $roleIds;

        return $this->nexus->ok($this->userView($state, $state['users'][$userId]));
    }

    private function revoke(array &$state, string $userId, string $actor)
    {
        if ($denied = $this->target($state, $userId)) {
            return $denied;
        }
        if ($userId === $actor) {
            return $this->nexus->fail('SELF_REVOCATION_DENIED', 'You cannot remove your own access.', 409);
        }
        unset($state['users'][$userId]);

        return $this->nexus->ok(null);
    }

    private function refusedPermissions(array $state, array $keys): array
    {
        return array_values(array_filter(array_unique($keys), fn ($k) => ! in_array($k, $this->delegableKeys($state), true)));
    }

    private function createRole(array &$state, array $input)
    {
        if (empty($input['name']) || empty($input['permissions']) || ! is_array($input['permissions'])) {
            return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['permissions' => ['The permissions field is required.']]);
        }
        if ($refused = $this->refusedPermissions($state, $input['permissions'])) {
            return $this->nexus->fail('PERMISSION_NOT_DELEGABLE', 'A permission is unknown, outside this application, not delegable or not covered by the tenant entitlements.', 422, ['permissions' => $refused]);
        }
        $id = FakeNexus::uuid();
        $state['roles'][$id] = ['id' => $id, 'code' => strtoupper(Str::slug($input['name'], '_')).'_'.strtoupper(Str::random(4)), 'name' => $input['name'],
            'description' => $input['description'] ?? null, 'template' => false, 'editable' => true, 'permissions' => array_values(array_unique($input['permissions']))];

        return $this->nexus->ok($this->roleView($state['roles'][$id]), 201);
    }

    private function editable(array $state, string $roleId)
    {
        $role = $state['roles'][$roleId] ?? null;

        return $role && $role['editable'] ? null : $this->nexus->fail('ROLE_NOT_EDITABLE', 'This role cannot be changed from a product.', 404);
    }

    private function updateRole(array &$state, string $roleId, array $input)
    {
        if ($denied = $this->editable($state, $roleId)) {
            return $denied;
        }
        if (isset($input['permissions'])) {
            if ($refused = $this->refusedPermissions($state, $input['permissions'])) {
                return $this->nexus->fail('PERMISSION_NOT_DELEGABLE', 'A permission is unknown, outside this application, not delegable or not covered by the tenant entitlements.', 422, ['permissions' => $refused]);
            }
            $state['roles'][$roleId]['permissions'] = array_values(array_unique($input['permissions']));
        }
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                $state['roles'][$roleId][$field] = $input[$field];
            }
        }

        return $this->nexus->ok($this->roleView($state['roles'][$roleId]));
    }

    private function deleteRole(array &$state, string $roleId)
    {
        if ($denied = $this->editable($state, $roleId)) {
            return $denied;
        }
        foreach ($state['users'] as $user) {
            if (in_array($roleId, $user['roles'], true)) {
                return $this->nexus->fail('ROLE_IN_USE', 'The role is still assigned to users.', 409);
            }
        }
        unset($state['roles'][$roleId]);

        return $this->nexus->ok(null);
    }
}
