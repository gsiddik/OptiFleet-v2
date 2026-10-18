<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;

/**
 * The tenant delegation API of OptiNexus (contract: GOVERNANCE_CONTRACTS.md section 2) as an in-memory state machine for tests.
 * It enforces the documented rules, so a test of OptiFleet's screens and mapping meets the same refusals the real service gives:
 * the acting user must be an Admin Tenant, only the delegable set may be handed out, roles from other tenants are not
 * assignable, an Admin Tenant is protected, a user cannot remove their own access, identities are linked by e-mail not copied.
 * Invitations follow section 2b: one open invitation per e-mail, no invitation for an active member, resend only for PENDING and
 * EXPIRED invitations (cooldown 429 with Retry-After, limit 409), revoke is idempotent, EXPIRED is derived from `expires_at`, and the
 * token never appears in an answer (unless a test sets `leak_token` to prove that OptiFleet would not pass it on).
 *
 * State (per OptiNexus tenant id), seeded by FakeNexus::delegationTenant():
 *   admins: subjects of Admin Tenants; delegable: the explicit delegable catalog; roles; users; identities (e-mail => subject);
 *   invitations (id => row); knobs `invite_cooldown` (s), `invite_resend_limit`, `invite_rate_limit` (null or max creations), `leak_token`.
 * Seats (section 2d): `seat` is ['state' => NOT_CONTROLLED|LIMITED|UNLIMITED|ENTITLEMENT_*, 'limit' => n]; OptiFleet declares no `user_limit`, so a seeded tenant
 * is NOT_CONTROLLED until a test says otherwise. Used = people holding ACTIVE access; reserved = open, unexpired invitations of people holding none.
 */
class FakeNexusDelegation
{
    public function __construct(private readonly FakeNexus $nexus) {}

    public static function seed(FakeNexus $nexus, string $tenant, array $admins, array $delegable, string $status = 'ACTIVE', bool $subscribed = true): void
    {
        $nexus->delegation[$tenant] = [
            'status' => $status, 'subscribed' => $subscribed, 'admins' => $admins,
            'delegable' => $delegable, 'roles' => [], 'users' => [], 'identities' => [], 'invitations' => [],
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
            $method === 'GET' && $rest === '/invitations' => $this->listInvitations($state, $input),
            $method === 'POST' && $rest === '/invitations' => $this->createInvitation($state, $input, $actor),
            $method === 'POST' && (bool) preg_match('#^/invitations/([^/]+)/resend$#', $rest, $inv) => $this->resendInvitation($state, $inv[1]),
            $method === 'POST' && (bool) preg_match('#^/invitations/([^/]+)/revoke$#', $rest, $inv) => $this->revokeInvitation($state, $inv[1], $input),
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
            'seats' => $this->seatStatus($state),
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
        if (! empty($state['direct_users_disabled'])) {
            return $this->nexus->fail('DIRECT_USER_CREATION_DISABLED', 'Direct user creation is switched off for this deployment. Invite the person instead.', 410);
        }
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
        if (! ($existing && $existing['application_access'] === 'ACTIVE') && ($refusal = $this->seatRefusal($state, take: true, email: $email))) {
            return $refusal;
        }
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

    // ------------------------------------------------------------ invitations

    private function invitationStatus(array $row): string
    {
        if ($row['status'] === 'PENDING' && strtotime($row['expires_at']) <= now()->getTimestamp()) {
            return 'EXPIRED';
        }

        return $row['status'];
    }

    private function invitationView(array $state, array $row): array
    {
        $status = $this->invitationStatus($row);
        $open = in_array($status, ['PENDING', 'EXPIRED'], true);
        $roles = array_values(array_map(fn (string $id) => ['id' => $id, 'code' => $state['roles'][$id]['code'], 'name' => $state['roles'][$id]['name']], array_filter($row['role_ids'], fn ($id) => isset($state['roles'][$id]))));

        return [
            'id' => $row['id'], 'email' => $row['email'], 'name' => $row['name'], 'status' => $status, 'locale' => $row['locale'],
            'role_ids' => $row['role_ids'], 'roles' => $roles, 'invited_by' => $row['invited_by'],
            'created_at' => $row['created_at'], 'expires_at' => $row['expires_at'], 'last_sent_at' => $row['last_sent_at'], 'send_count' => $row['send_count'],
            'delivery' => $row['delivery'], 'accepted_at' => $row['accepted_at'], 'revoked_at' => $row['revoked_at'],
            'can_resend' => $open && $row['send_count'] < ($state['invite_resend_limit'] ?? 5), 'can_revoke' => $open,
        ] + (! empty($state['leak_token']) ? ['token' => $row['token'], 'token_hash' => hash('sha256', $row['token'])] : []);
    }

    private function createInvitation(array &$state, array $input, string $actor)
    {
        foreach (['email', 'name'] as $field) {
            if (empty($input[$field])) {
                return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, [$field => ["The {$field} field is required."]]);
            }
        }
        if (! filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['email' => ['The email field must be a valid email address.']]);
        }
        if (isset($input['locale']) && ! in_array($input['locale'], ['en', 'id'], true)) {
            return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['locale' => ['The selected locale is invalid.']]);
        }
        $roleIds = array_values(array_unique($input['role_ids'] ?? []));
        if ($refused = $this->resolveRoles($state, $roleIds)) {
            return $this->nexus->fail('ROLE_NOT_ASSIGNABLE', 'A role is unknown, belongs to another tenant or application, or carries permissions that are not delegable.', 422, ['role_ids' => $refused]);
        }

        $email = strtolower(trim($input['email']));
        $memberId = $state['identities'][$email] ?? null;
        if ($memberId && (($state['users'][$memberId]['application_access'] ?? null) === 'ACTIVE')) {
            return $this->nexus->fail('ALREADY_MEMBER', 'This person is already a member with access to this application.', 409);
        }
        foreach ($state['invitations'] as $row) {
            if ($row['email'] === $email && $this->invitationStatus($row) === 'PENDING') {
                return $this->nexus->fail('INVITATION_PENDING', 'There is already an open invitation for this e-mail address.', 409, ['invitation_id' => $row['id']]);
            }
        }
        if (isset($state['invite_rate_limit']) && count($state['invitations']) >= $state['invite_rate_limit']) {
            return $this->nexus->fail('INVITATION_RATE_LIMITED', 'Too many invitations were created in a short time.', 429, null, ['Retry-After' => '120']);
        }
        if ($refusal = $this->seatRefusal($state, take: false, email: $email)) {
            return $refusal;
        }

        $id = FakeNexus::uuid();
        $state['invitations'][$id] = [
            'id' => $id, 'email' => $email, 'name' => $input['name'], 'status' => 'PENDING', 'locale' => $input['locale'] ?? 'en', 'role_ids' => $roleIds,
            'invited_by' => ['id' => $actor, 'name' => $state['users'][$actor]['name'] ?? 'Administrator'],
            'created_at' => now()->toIso8601String(), 'expires_at' => now()->addHours(72)->toIso8601String(), 'last_sent_at' => now()->toIso8601String(), 'send_count' => 1,
            'delivery' => ['status' => 'QUEUED', 'attempted_at' => null, 'error_code' => null], 'accepted_at' => null, 'revoked_at' => null,
            'token' => bin2hex(random_bytes(32)),
        ];

        return $this->nexus->ok($this->invitationView($state, $state['invitations'][$id]), 201);
    }

    private function listInvitations(array $state, array $input)
    {
        $status = strtoupper((string) ($input['status'] ?? ''));
        if ($status !== '' && ! in_array($status, ['PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED'], true)) {
            return $this->nexus->fail('VALIDATION_ERROR', 'The request contains invalid data.', 422, ['status' => ['The selected status is invalid.']]);
        }
        $search = strtolower((string) ($input['search'] ?? ''));
        $rows = array_reverse(array_values($state['invitations']));
        $rows = array_values(array_filter($rows, fn ($r) => ($status === '' || $this->invitationStatus($r) === $status)
            && ($search === '' || str_contains(strtolower($r['name'].' '.$r['email']), $search))));
        $per = max(1, min(100, (int) ($input['per_page'] ?? 25)));

        return $this->nexus->ok(array_map(fn ($r) => $this->invitationView($state, $r), array_slice($rows, 0, $per)), 200, [
            'pagination' => ['current_page' => 1, 'per_page' => $per, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $per))],
            'seats' => $this->seatStatus($state),
        ]);
    }

    private function resendInvitation(array &$state, string $id)
    {
        if (! isset($state['invitations'][$id])) {
            return $this->nexus->fail('INVITATION_NOT_FOUND', 'The invitation was not found.', 404);
        }
        $row = &$state['invitations'][$id];
        $status = $this->invitationStatus($row);
        if (! in_array($status, ['PENDING', 'EXPIRED'], true)) {
            return $this->nexus->fail('INVITATION_NOT_PENDING', 'The invitation is not pending.', 409, ['status' => $status]);
        }
        if ($row['send_count'] >= ($state['invite_resend_limit'] ?? 5)) {
            return $this->nexus->fail('INVITATION_RESEND_LIMIT', 'This invitation was resent the maximum number of times.', 409);
        }
        $wait = ($state['invite_cooldown'] ?? 60) - (now()->getTimestamp() - strtotime($row['last_sent_at']));
        if ($wait > 0) {
            return $this->nexus->fail('INVITATION_RESEND_COOLDOWN', 'This invitation was sent a moment ago.', 429, null, ['Retry-After' => (string) $wait]);
        }

        if ($status === 'EXPIRED' && ($refusal = $this->seatRefusal($state, take: false, email: $row['email']))) {
            return $refusal; // an expired invitation holds no seat: opening it again must find one
        }

        $row['token'] = bin2hex(random_bytes(32)); // the old link stops working at once
        $row['status'] = 'PENDING';
        $row['expires_at'] = now()->addHours(72)->toIso8601String();
        $row['last_sent_at'] = now()->toIso8601String();
        $row['send_count']++;
        $row['delivery'] = ['status' => 'QUEUED', 'attempted_at' => null, 'error_code' => null];

        return $this->nexus->ok($this->invitationView($state, $row));
    }

    private function revokeInvitation(array &$state, string $id, array $input)
    {
        if (! isset($state['invitations'][$id])) {
            return $this->nexus->fail('INVITATION_NOT_FOUND', 'The invitation was not found.', 404);
        }
        $row = &$state['invitations'][$id];
        if ($row['status'] === 'ACCEPTED') {
            return $this->nexus->fail('INVITATION_NOT_PENDING', 'The invitation is not pending.', 409, ['status' => 'ACCEPTED']);
        }
        if ($row['status'] !== 'REVOKED') {
            $row['status'] = 'REVOKED';
            $row['revoked_at'] = now()->toIso8601String();
            $row['revoke_reason'] = $input['reason'] ?? null;
        }

        return $this->nexus->ok($this->invitationView($state, $row));
    }

    // ------------------------------------------------------------ seats (section 2d)

    private function holdsSeat(array $state, string $email): bool
    {
        $id = $state['identities'][$email] ?? null;

        return $id !== null && (($state['users'][$id]['application_access'] ?? null) === 'ACTIVE');
    }

    /** @return list<string> */
    private function reservedEmails(array $state): array
    {
        $emails = [];
        foreach ($state['invitations'] as $row) {
            if ($this->invitationStatus($row) === 'PENDING' && ! $this->holdsSeat($state, $row['email'])) {
                $emails[$row['email']] = true;
            }
        }

        return array_keys($emails);
    }

    /** @return array<string, mixed> what OptiNexus answers in `meta.seats` */
    private function seatStatus(array $state): array
    {
        $seat = $state['seat'] ?? ['state' => 'NOT_CONTROLLED'];
        $limited = $seat['state'] === 'LIMITED';
        $limit = $limited ? (int) $seat['limit'] : null;
        $used = count(array_filter($state['users'], fn ($u) => ($u['application_access'] ?? null) === 'ACTIVE'));
        $reserved = count($this->reservedEmails($state));
        $overBy = $limited ? max(0, $used + $reserved - $limit) : 0;
        $available = $limited ? max(0, $limit - $used - $reserved) : null;
        $blocked = match (true) {
            $seat['state'] === 'ENTITLEMENT_MISSING' => 'SEAT_ENTITLEMENT_MISSING',
            $seat['state'] === 'ENTITLEMENT_INVALID' => 'SEAT_ENTITLEMENT_INVALID',
            $seat['state'] === 'ENTITLEMENT_UNAVAILABLE' => 'SEAT_ENTITLEMENT_UNAVAILABLE',
            $limited && $overBy > 0 => 'SEAT_OVER_LIMIT',
            $limited && $available === 0 => 'SEAT_LIMIT_REACHED',
            default => null,
        };

        return ['application_code' => FakeNexus::APP, 'controlled' => $seat['state'] !== 'NOT_CONTROLLED', 'state' => $seat['state'], 'limit' => $limit, 'unlimited' => $seat['state'] === 'UNLIMITED',
            'used' => $used, 'reserved' => $reserved, 'available' => $available, 'over_limit' => $overBy > 0, 'over_by' => $overBy, 'can_add' => $blocked === null, 'blocked_reason' => $blocked];
    }

    /** The refusal OptiNexus gives an addition that does not fit, or null. `take` = access now (direct add); otherwise an invitation reserving a seat. */
    private function seatRefusal(array $state, bool $take, string $email)
    {
        $status = $this->seatStatus($state);
        if (! in_array($status['state'], ['NOT_CONTROLLED', 'LIMITED', 'UNLIMITED'], true)) {
            $code = $status['blocked_reason'];
            $transient = $code === 'SEAT_ENTITLEMENT_UNAVAILABLE';

            return $this->nexus->fail($code, 'The seat limit could not be decided.', $transient ? 503 : 409, ['recovery' => $transient ? 'RETRY' : 'SET_ENTITLEMENT'], $transient ? ['Retry-After' => '5'] : []);
        }
        if ($status['state'] !== 'LIMITED' || $this->holdsSeat($state, $email)) {
            return null;
        }

        $reservedHere = in_array($email, $this->reservedEmails($state), true);
        if (! $take && $reservedHere) {
            return null;
        }
        $fits = $take && $reservedHere ? $status['limit'] >= $status['used'] + 1 : $status['limit'] >= $status['used'] + $status['reserved'] + 1;
        if ($fits) {
            return null;
        }

        $over = $status['limit'] < $status['used'] + $status['reserved'];

        return $this->nexus->fail($over ? 'SEAT_OVER_LIMIT' : 'SEAT_LIMIT_REACHED', $over ? 'This application is over its seat limit.' : 'No seat is left for this application.', 409,
            ['application_code' => FakeNexus::APP, 'limit' => $status['limit'], 'used' => $status['used'], 'reserved' => $status['reserved'], 'available' => $status['available'], 'over_limit' => $over,
                'recovery' => $over ? 'REDUCE_USAGE_OR_RAISE_LIMIT' : 'FREE_SEAT_OR_RAISE_LIMIT']);
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
