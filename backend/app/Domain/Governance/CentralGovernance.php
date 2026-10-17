<?php

namespace App\Domain\Governance;

use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The central authority resolver (audit A02, R05). For a tenant in CENTRAL mode OptiNexus alone decides who may use OptiFleet,
 * what they may do and which modules the tenant holds; this class asks it (bulk calls, one per user and one per tenant, never
 * one per permission), keeps each answer for at most `optinexus.governance.ttl_seconds` (300 s ceiling) and never uses an answer
 * after that. When OptiNexus cannot answer, GovernanceUnavailableException is raised and the caller denies: there is no stale
 * grant, no local fallback and no widening. Local roles and data scopes are applied on top by their owners and can only narrow.
 *
 * Revocation latency: a change in OptiNexus is in force in OptiFleet at most one TTL later (<= 300 s); back-channel logout and
 * access-revoked events end the sessions at once and drop the cached answers (forgetUser / forgetTenant).
 */
class CentralGovernance
{
    /** @var array<string, array{at: int, value: ?array{mode: string, nexus_tenant_id: ?string}}> */
    private array $links = [];

    /** @var array<string, AccessSnapshot> */
    private array $snapshots = [];

    /** @var array<string, array{tenant_status: ?string, capabilities: array<int, string>, fetched_at: int, expires_at: int}> */
    private array $entitlementMemo = [];

    /** @var ?array{at: int, rows: Collection<int, object>} */
    private ?array $modules = null;

    public function __construct(private readonly NexusGovernanceClient $client) {}

    // ------------------------------------------------------------------ mode

    public function governs(?string $tenantId): bool
    {
        return $tenantId !== null && ($this->link($tenantId)['mode'] ?? 'LOCAL') === 'CENTRAL';
    }

    /** @return ?array{mode: string, nexus_tenant_id: ?string} */
    public function link(string $tenantId): ?array
    {
        $now = now()->getTimestamp();
        if (isset($this->links[$tenantId]) && $this->links[$tenantId]['at'] + 5 > $now) {
            return $this->links[$tenantId]['value'];
        }

        $row = Tenant::query()->whereKey($tenantId)->first(['id', 'governance_mode', 'optinexus_tenant_id']);
        $value = $row ? ['mode' => (string) $row->governance_mode, 'nexus_tenant_id' => $row->optinexus_tenant_id] : null;
        $this->links[$tenantId] = ['at' => $now, 'value' => $value];

        return $value;
    }

    // --------------------------------------------------------- user snapshot

    /**
     * @throws GovernanceUnavailableException when OptiNexus cannot answer (callers deny)
     */
    public function snapshot(User $user, string $tenantId): AccessSnapshot
    {
        $link = $this->link($tenantId);
        if (! $link || $link['mode'] !== 'CENTRAL' || ! $link['nexus_tenant_id']) {
            return AccessSnapshot::denied('tenant_not_governed');
        }

        // Without a central identity there is nothing to ask: such a user has no access in a centrally governed tenant.
        if (! $user->optinexus_subject) {
            return AccessSnapshot::denied('identity_not_linked');
        }

        $key = 'gov:access:'.hash('sha256', $link['nexus_tenant_id'].'|'.$user->optinexus_subject);

        return $this->remember($key, $this->snapshots, fn () => $this->fetchSnapshot($user->optinexus_subject, $link['nexus_tenant_id']),
            fn (AccessSnapshot $s) => $s->toArray(), fn (array $a) => AccessSnapshot::fromArray($a), fn (AccessSnapshot $s) => $s->expiresAt);
    }

    private function fetchSnapshot(string $subject, string $nexusTenantId): AccessSnapshot
    {
        $permissions = $this->client->effectivePermissions($subject, $nexusTenantId);
        $now = now()->getTimestamp();

        if ($permissions['allowed'] && $permissions['permissions'] !== []) {
            $mapped = [];
            foreach ($permissions['permissions'] as $key => $scope) {
                if (($name = GovernanceCatalog::localName($key)) !== null) {
                    $mapped[$name] = $scope;
                }
            }

            return new AccessSnapshot(true, null, $mapped, $now, $now + $permissions['ttl_seconds']);
        }

        // Nothing granted: ask why. A user who lost the application altogether must lose the session; a user who is merely
        // without roles keeps an (empty) session and sees an explicit "no access" state.
        $context = $this->client->productContext($subject, $nexusTenantId);
        $expires = $now + min($permissions['ttl_seconds'], $context['ttl_seconds']);

        if (! $context['allowed']) {
            return new AccessSnapshot(false, $context['deny_reason'] ?? $permissions['deny_reason'] ?? 'access_denied', [], $now, $expires);
        }

        return new AccessSnapshot(true, $permissions['deny_reason'] ?? 'PERMISSION_NOT_GRANTED', [], $now, $expires);
    }

    /**
     * The permissions the user holds in a CENTRAL tenant: what OptiNexus grants, narrowed by the user's local roles when they
     * have any (local roles can restrict, never add).
     *
     * @param  ?Collection<int, string>  $localRestriction  union of the permissions of the user's local roles, null if none assigned
     * @return Collection<int, string>
     */
    public function permissionsFor(User $user, string $tenantId, ?Collection $localRestriction): Collection
    {
        $snapshot = $this->snapshot($user, $tenantId);
        if (! $snapshot->sessionAllowed) {
            return collect();
        }

        $granted = collect(array_keys($snapshot->permissions));

        return ($localRestriction === null ? $granted : $granted->intersect($localRestriction))->values();
    }

    /**
     * What the UI is told about the authority behind the signed-in session (additive `governance` block of /auth/me).
     *
     * @return array{mode: string, source: string, status: ?string, refreshed_at: ?string, expires_at: ?string}
     */
    public function describe(User $user, string $tenantId): array
    {
        if (! $this->governs($tenantId)) {
            return ['mode' => 'LOCAL', 'source' => 'local', 'status' => null, 'refreshed_at' => null, 'expires_at' => null];
        }

        $snapshot = $this->snapshot($user, $tenantId);

        return [
            'mode' => 'CENTRAL',
            'source' => 'optinexus',
            'status' => $snapshot->sessionAllowed ? ($snapshot->permissions === [] ? 'NO_PERMISSIONS' : 'OK') : strtoupper((string) $snapshot->denyReason),
            'refreshed_at' => now()->setTimestamp($snapshot->fetchedAt)->toIso8601String(),
            'expires_at' => now()->setTimestamp($snapshot->expiresAt)->toIso8601String(),
        ];
    }

    // ------------------------------------------------------ tenant entitlement

    /**
     * Module codes the tenant holds in OptiFleet according to OptiNexus: the core modules while the tenant is ACTIVE, and every
     * other module whose capability the tenant is entitled to.
     *
     * @return Collection<int, string>
     *
     * @throws GovernanceUnavailableException
     */
    public function tenantModules(string $tenantId): Collection
    {
        $entitlements = $this->entitlements($tenantId);
        $capabilities = array_flip($entitlements['capabilities']);
        $active = $entitlements['tenant_status'] === 'ACTIVE';

        return $this->moduleRows()
            ->filter(fn (object $m): bool => $active && ($m->is_core || isset($capabilities[GovernanceCatalog::capabilityCode($m->code)])))
            ->pluck('code')->values();
    }

    /** @return array{tenant_status: ?string, capabilities: array<int, string>, fetched_at: int, expires_at: int} */
    public function entitlements(string $tenantId): array
    {
        $link = $this->link($tenantId);
        if (! $link || $link['mode'] !== 'CENTRAL' || ! $link['nexus_tenant_id']) {
            throw new GovernanceContractException('The tenant is not governed by OptiNexus.');
        }

        $key = 'gov:entitlements:'.hash('sha256', $link['nexus_tenant_id']);

        return $this->remember($key, $this->entitlementMemo, function () use ($link): array {
            $context = $this->client->commercialContext($link['nexus_tenant_id']);
            $now = now()->getTimestamp();

            return ['tenant_status' => $context['tenant_status'], 'capabilities' => $context['capabilities'], 'fetched_at' => $now,
                'expires_at' => $now + (int) config('optinexus.governance.ttl_seconds')];
        }, fn (array $a) => $a, fn (array $a) => $a, fn (array $a) => (int) $a['expires_at']);
    }

    /** @return Collection<int, object{code: string, is_core: bool}> */
    private function moduleRows(): Collection
    {
        $now = now()->getTimestamp();
        if ($this->modules === null || $now >= $this->modules['at'] + 60) {
            $this->modules = ['at' => $now, 'rows' => DB::table('modules')->get(['code', 'is_core'])->map(fn ($m) => (object) ['code' => $m->code, 'is_core' => (bool) $m->is_core])];
        }

        return $this->modules['rows'];
    }

    // ----------------------------------------------------------- invalidation

    public function forgetUser(User $user, ?string $tenantId = null): void
    {
        if (! $user->optinexus_subject) {
            return;
        }

        $tenants = $tenantId ? [$tenantId] : TenantUser::query()->where('user_id', $user->id)->pluck('tenant_id')->all();
        foreach ($tenants as $id) {
            $nexusTenant = Tenant::query()->whereKey($id)->value('optinexus_tenant_id');
            if ($nexusTenant) {
                $key = 'gov:access:'.hash('sha256', $nexusTenant.'|'.$user->optinexus_subject);
                Cache::forget($key);
                unset($this->snapshots[$key]);
            }
        }
    }

    /** Drops the cached access answer of every member of the tenant (a role they hold changed in OptiNexus). */
    public function forgetTenantUsers(string $tenantId): void
    {
        $nexusTenant = Tenant::query()->whereKey($tenantId)->value('optinexus_tenant_id');
        if (! $nexusTenant) {
            return;
        }

        User::query()->join('tenant_users', 'tenant_users.user_id', '=', 'users.id')->where('tenant_users.tenant_id', $tenantId)
            ->whereNotNull('users.optinexus_subject')->pluck('users.optinexus_subject')->each(function (string $subject) use ($nexusTenant): void {
                $key = 'gov:access:'.hash('sha256', $nexusTenant.'|'.$subject);
                Cache::forget($key);
                unset($this->snapshots[$key]);
            });
    }

    public function forgetTenant(string $tenantId): void
    {
        $nexusTenant = Tenant::query()->whereKey($tenantId)->value('optinexus_tenant_id');
        if ($nexusTenant) {
            $key = 'gov:entitlements:'.hash('sha256', $nexusTenant);
            Cache::forget($key);
            unset($this->entitlementMemo[$key]);
        }
        unset($this->links[$tenantId]);
    }

    /** Drops every in-process memo (long-running workers; tests). Shared cache entries expire by themselves within the TTL. */
    public function flushMemo(): void
    {
        $this->links = [];
        $this->snapshots = [];
        $this->entitlementMemo = [];
        $this->modules = null;
    }

    // ------------------------------------------------------------------ cache

    /**
     * Read-through with a hard freshness check on every read (memo, then shared cache), and a lock so a cold cache costs one
     * round trip to OptiNexus instead of one per concurrent request.
     *
     * @template T
     *
     * @param  array<string, T>  $memo
     * @param  callable(): T  $fetch
     * @param  callable(T): array  $dehydrate
     * @param  callable(array): T  $hydrate
     * @param  callable(T): int  $expiresAt
     * @return T
     */
    private function remember(string $key, array &$memo, callable $fetch, callable $dehydrate, callable $hydrate, callable $expiresAt)
    {
        $fresh = function () use ($key, &$memo, $hydrate, $expiresAt) {
            $now = now()->getTimestamp();
            if (isset($memo[$key]) && $expiresAt($memo[$key]) > $now) {
                return $memo[$key];
            }
            $stored = Cache::get($key);
            if (is_array($stored)) {
                $value = $hydrate($stored);
                if ($expiresAt($value) > $now) {
                    return $memo[$key] = $value;
                }
            }

            return null;
        };

        if (($hit = $fresh()) !== null) {
            return $hit;
        }

        $lock = Cache::lock($key.':lock', 15);
        $locked = false;
        try {
            $locked = $lock->block(4);
        } catch (LockTimeoutException) {
            // Another request is refreshing for too long: carry on with our own bounded call.
        }

        try {
            if (($hit = $fresh()) !== null) {
                return $hit;
            }

            try {
                $value = $fetch();
            } catch (GovernanceUnavailableException|GovernanceContractException $e) {
                // Never fall back to what an earlier call stored: drop it so a later request cannot read it either.
                unset($memo[$key]);
                Cache::forget($key);
                Log::warning('Central governance lookup failed; access is denied.', ['reason' => $e->getMessage()]);

                throw $e;
            }

            $memo[$key] = $value;
            Cache::put($key, $dehydrate($value), max(1, $expiresAt($value) - now()->getTimestamp()));

            return $value;
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }
}
