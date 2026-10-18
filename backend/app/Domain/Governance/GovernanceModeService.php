<?php

namespace App\Domain\Governance;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Moves a tenant between the two governance modes (audit A02, R05) and describes what such a move would do. Nothing here
 * migrates data automatically: a tenant becomes CENTRAL only when a platform operator runs `optinexus:governance:enable`
 * after the readiness check passed, and the local roles, data scopes and memberships stay exactly as they are (roles turn into
 * restrictions on top of the central grants; nothing is copied to OptiNexus and nothing is deleted).
 */
class GovernanceModeService
{
    /** Members whose OptiNexus access is looked up in a readiness check (bounded: one request each). */
    private const SAMPLE = 20;

    public function __construct(
        private readonly NexusGovernanceClient $client,
        private readonly CentralGovernance $central,
        private readonly AuditService $audit,
    ) {}

    /**
     * Read-only inventory of one tenant: what is linked, what is local only, what would change on cutover.
     *
     * @return array<string, mixed>
     */
    public function inventory(Tenant $tenant): array
    {
        $members = TenantUser::query()->where('tenant_id', $tenant->id)->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->get(['tenant_users.status as membership_status', 'users.status as user_status', 'users.optinexus_subject', 'users.user_type']);
        $active = $members->filter(fn ($m) => $m->membership_status === 'active' && $m->user_status === 'active');

        return [
            'tenant_id' => $tenant->id,
            'code' => $tenant->code,
            'status' => $tenant->status,
            'mode' => $tenant->governance_mode,
            'linked_to_optinexus' => $tenant->optinexus_tenant_id !== null,
            'members_total' => $members->count(),
            'members_active' => $active->count(),
            'members_linked' => $active->filter(fn ($m) => $m->optinexus_subject !== null)->count(),
            // They keep a local password today; in CENTRAL mode they sign in with OptiNexus and are linked on that first
            // sign-in by their verified e-mail (never by name, never to an already linked account).
            'members_unlinked' => $active->filter(fn ($m) => $m->optinexus_subject === null)->count(),
            'local_role_assignments' => DB::table('role_assignments')->where('tenant_id', $tenant->id)->count(),
            'members_with_local_roles' => DB::table('role_assignments')->where('tenant_id', $tenant->id)->distinct()->count('user_id'),
            'local_data_scopes' => DB::table('data_scope_assignments')->where('tenant_id', $tenant->id)->count(),
            'local_module_entitlements' => DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->count(),
            'enabled_at' => $tenant->governance_enabled_at,
        ];
    }

    /**
     * Whether the tenant can be switched to CENTRAL now, with every reason why not (blockers) and everything an operator should
     * know first (warnings). Asks OptiNexus (read only, bounded).
     *
     * @return array{ready: bool, blockers: array<int, string>, warnings: array<int, string>, sampled: int, sampled_with_access: int}
     */
    public function readiness(Tenant $tenant): array
    {
        $blockers = [];
        $warnings = [];
        $sampled = 0;
        $withAccess = 0;

        if ($tenant->governance_mode === 'CENTRAL') {
            $warnings[] = 'The tenant is already governed centrally.';
        }
        if (! config('optinexus.enabled') || ! config('optinexus.base_url')) {
            $blockers[] = 'The OptiNexus integration is not configured (OPTINEXUS_ENABLED, OPTINEXUS_BASE_URL).';
        }
        if (! config('optinexus.gateway.client_id') || ! config('optinexus.gateway.client_secret')) {
            $blockers[] = 'The Fleet service account is not configured (OPTINEXUS_GATEWAY_CLIENT_ID / _SECRET); it needs the scopes authorization.check and commercial.read.';
        }
        if (! config('optinexus.sso.client_id') || ! config('optinexus.sso.redirect_uri')) {
            $blockers[] = 'SSO is not configured (OPTINEXUS_SSO_CLIENT_ID, OPTINEXUS_SSO_REDIRECT_URI): nobody could sign in to a central tenant.';
        }
        if (! $tenant->optinexus_tenant_id) {
            $blockers[] = 'The tenant is not linked to an OptiNexus tenant (tenants.optinexus_tenant_id). Link it first; this tool never guesses a link.';
        }
        if ($tenant->status !== 'ACTIVE') {
            $warnings[] = "The local tenant status is {$tenant->status}.";
        }

        if ($blockers === []) {
            try {
                $context = $this->client->commercialContext($tenant->optinexus_tenant_id);
                if ($context['tenant_status'] !== 'ACTIVE') {
                    $blockers[] = 'OptiNexus reports the tenant as '.($context['tenant_status'] ?? 'unknown').', not ACTIVE.';
                }

                $subjects = User::query()->join('tenant_users', 'tenant_users.user_id', '=', 'users.id')
                    ->where('tenant_users.tenant_id', $tenant->id)->where('tenant_users.status', 'active')->where('users.status', 'active')
                    ->whereNotNull('users.optinexus_subject')->limit(self::SAMPLE)->pluck('users.optinexus_subject');
                foreach ($subjects as $subject) {
                    $sampled++;
                    $answer = $this->client->effectivePermissions($subject, $tenant->optinexus_tenant_id);
                    $withAccess += ($answer['allowed'] && $answer['permissions'] !== []) ? 1 : 0;
                }
                if ($sampled > 0 && $withAccess === 0) {
                    $blockers[] = 'None of the sampled linked members holds any optifleet permission in OptiNexus: switching now would lock everybody out.';
                } elseif ($withAccess < $sampled) {
                    $warnings[] = ($sampled - $withAccess)." of {$sampled} sampled linked members hold no optifleet permission in OptiNexus and would lose access.";
                }
            } catch (GovernanceUnavailableException|GovernanceContractException $e) {
                $blockers[] = 'OptiNexus cannot be asked right now: '.$e->getMessage();
            }
        }

        $inventory = $this->inventory($tenant);
        if ($inventory['members_linked'] === 0) {
            $warnings[] = 'No active member is linked to an OptiNexus identity yet; nobody holds a central grant until OptiNexus provisions or links one (first SSO sign-in).';
        }
        if ($inventory['members_unlinked'] > 0) {
            $warnings[] = "{$inventory['members_unlinked']} active members have a local password only. They lose password sign-in and are linked on their first SSO sign-in by verified e-mail; they hold no access until OptiNexus grants it.";
        }
        if ($inventory['local_role_assignments'] > 0) {
            $warnings[] = "{$inventory['local_role_assignments']} local role assignments stay in place but can only restrict what OptiNexus grants.";
        }
        if ($inventory['local_module_entitlements'] > 0) {
            $warnings[] = 'Local module entitlements stop counting; the modules come from the OptiNexus subscription.';
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings, 'sampled' => $sampled, 'sampled_with_access' => $withAccess];
    }

    /**
     * @return array{revoked_sessions: int}
     *
     * @throws \RuntimeException when the readiness check has blockers
     */
    public function enable(Tenant $tenant, User $operator): array
    {
        $readiness = $this->readiness($tenant);
        if (! $readiness['ready']) {
            throw new \RuntimeException('Not ready: '.implode(' ', $readiness['blockers']));
        }

        $revoked = DB::transaction(function () use ($tenant, $operator, $readiness): int {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $before = $locked->governance_mode;
            $locked->forceFill(['governance_mode' => 'CENTRAL', 'governance_enabled_at' => now(), 'governance_enabled_by' => $operator->id])->save();

            // A password session cannot carry over: only an OptiNexus (SSO) session may enter a central tenant.
            $revoked = $this->revokeLocalSessions($locked);

            $this->audit->log('tenant', $locked->id, 'governance.enabled', ['governance_mode' => $before],
                ['governance_mode' => 'CENTRAL', 'operator_id' => $operator->id, 'revoked_sessions' => $revoked, 'warnings' => $readiness['warnings']], $locked->id);

            return $revoked;
        });

        $this->forget($tenant);

        return ['revoked_sessions' => $revoked];
    }

    /**
     * Break-glass: back to local governance. Local roles and local entitlements apply again and OptiNexus revocations stop
     * applying, so this is an incident tool, not an administration tool: it needs the operator and a reason, and is audited.
     */
    public function disable(Tenant $tenant, User $operator, string $reason): void
    {
        DB::transaction(function () use ($tenant, $operator, $reason): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $before = $locked->governance_mode;
            $locked->forceFill(['governance_mode' => 'LOCAL', 'governance_enabled_at' => null, 'governance_enabled_by' => null])->save();

            $this->audit->log('tenant', $locked->id, 'governance.disabled', ['governance_mode' => $before],
                ['governance_mode' => 'LOCAL', 'operator_id' => $operator->id, 'reason' => $reason, 'break_glass' => true], $locked->id);
        });

        $this->forget($tenant);
    }

    /** @return Collection<int, Tenant> */
    public function tenants(?string $idOrCode = null): Collection
    {
        return Tenant::query()->when($idOrCode, fn ($q) => $q->where(fn ($w) => $w->where('code', $idOrCode)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/i', $idOrCode) ? $idOrCode : '00000000-0000-0000-0000-000000000000')))
            ->orderBy('code')->get();
    }

    private function revokeLocalSessions(Tenant $tenant): int
    {
        return PersonalAccessToken::query()
            ->where('abilities', 'like', '%"tenant:'.$tenant->id.'"%')
            ->where('abilities', 'not like', '%"sso"%')
            ->delete();
    }

    private function forget(Tenant $tenant): void
    {
        $this->central->forgetTenant($tenant->id);
        $this->central->flushMemo();
    }
}
