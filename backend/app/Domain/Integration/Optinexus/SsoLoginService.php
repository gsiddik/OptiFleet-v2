<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns verified OptiNexus claims into a local OptiFleet session.
 *
 * The tenant is taken from the verified `tenant_id` claim only; the user is matched on the stable OptiNexus subject, else
 * linked once by a verified e-mail (never re-bound to another subject).
 *
 *  - LEGACY tenant (governance_mode LOCAL): the user must already exist here with an active membership and keeps their local
 *    roles and data scope; OptiNexus decides who may enter, OptiFleet what they may do.
 *  - CENTRAL tenant (audit A02/R05): OptiNexus decides both. A person it lets in, who has no local record yet (for example one
 *    an Admin Tenant created from this application through the delegation API), gets a local projection: a record for
 *    ownership, audit and data scope, without a usable password and without any grant of its own. What the person may do is
 *    asked from OptiNexus on every request window (CentralGovernance). A membership an administrator switched off stays off.
 */
class SsoLoginService
{
    public function __construct(
        private readonly BackchannelLogoutService $lifecycle,
        private readonly AuditService $audit,
        private readonly SsoTenantProvisioner $tenants,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     * @return string one-time ticket the SPA exchanges for a session token
     *
     * @throws SsoException
     */
    public function ticketFor(array $claims): string
    {
        [$user, $tenant] = DB::transaction(fn () => $this->resolve($claims));

        $ticket = Str::random(48);
        Cache::put(
            'optinexus.sso.ticket.'.hash('sha256', $ticket),
            ['user_id' => $user->id, 'tenant_id' => $tenant->id, 'apps' => $claims['apps'] ?? [], 'id_token_hint' => $claims['_id_token_hint'] ?? null, 'sid' => $claims['sid'] ?? null],
            config('optinexus.sso.ticket_ttl_seconds'),
        );

        return $ticket;
    }

    /**
     * @return array{user: User, tenant: Tenant, apps: array<int, array<string, mixed>>}|null
     */
    public function redeemTicket(string $ticket): ?array
    {
        $data = Cache::pull('optinexus.sso.ticket.'.hash('sha256', $ticket));
        if (! $data) {
            return null;
        }

        $user = User::query()->find($data['user_id']);
        $tenant = Tenant::query()->find($data['tenant_id']);
        if (! $user || ! $tenant || $user->status !== 'active' || $tenant->status !== 'ACTIVE') {
            return null;
        }

        return ['user' => $user, 'tenant' => $tenant, 'apps' => $data['apps'], 'id_token_hint' => $data['id_token_hint'] ?? null, 'sid' => $data['sid'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{0: User, 1: Tenant}
     *
     * @throws SsoException
     */
    private function resolve(array $claims): array
    {
        if (! is_string($claims['tenant_id'] ?? null) || ! Str::isUuid($claims['tenant_id'])
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new SsoException('access_denied');
        }
        $tenant = Tenant::withTrashed()->where('optinexus_tenant_id', $claims['tenant_id'])->first()
            ?? $this->tenants->provision($claims);
        if ($tenant->trashed() || $tenant->status !== 'ACTIVE') {
            throw new SsoException('tenant_inactive');
        }

        $central = $tenant->governance_mode === 'CENTRAL';
        $user = User::query()->where('optinexus_subject', $claims['sub'])->first();

        if (! $user) {
            if (empty($claims['email']) || empty($claims['email_verified'])) {
                throw new SsoException('email_not_verified');
            }

            $user = User::query()->whereRaw('lower(email) = ?', [Str::lower($claims['email'])])->first();
            if (! $user) {
                if (! $central) {
                    throw new SsoException('user_not_provisioned');
                }

                $user = $this->provision($claims, $tenant);
            } else {
                if ($user->optinexus_subject !== null) {
                    // Same e-mail but already bound to a different OptiNexus identity: never re-bind.
                    throw new SsoException('account_conflict');
                }
                if ($user->user_type !== 'tenant') {
                    throw new SsoException('user_not_provisioned');
                }

                $user->forceFill(['optinexus_subject' => $claims['sub']])->save();
                $this->audit->log('User', $user->id, 'optinexus_identity_linked', null, ['optinexus_subject' => $claims['sub']], $tenant->id);
            }
        }

        if ($central) {
            $this->ensureMembership($user, $tenant);
        }

        // OptiNexus has just let this user in. If an earlier logout event from OptiNexus is what
        // switched the account off, undo exactly that (an administrator's own deactivation stays).
        $this->lifecycle->reactivate($user, $tenant);
        $user->refresh();

        if ($user->status !== 'active') {
            throw new SsoException('user_inactive');
        }

        $member = TenantUser::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->where('status', 'active')->exists();
        if (! $member) {
            throw new SsoException('no_membership');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return [$user, $tenant];
    }

    /**
     * Local projection of a central identity (CENTRAL tenants only): no usable password, no role. The e-mail was verified by
     * OptiNexus; a platform user or any other e-mail owner is never touched (the lookup above already ruled those out).
     *
     * @param  array<string, mixed>  $claims
     */
    private function provision(array $claims, Tenant $tenant): User
    {
        $email = Str::lower(trim((string) $claims['email']));
        $name = trim((string) ($claims['name'] ?? '')) !== '' ? (string) $claims['name'] : Str::before($email, '@');

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Str::random(64),
            'user_type' => 'tenant',
            'status' => 'active',
            'optinexus_subject' => $claims['sub'],
        ]);
        $this->audit->log('User', $user->id, 'provisioned_from_optinexus', null, ['optinexus_subject' => $claims['sub']], $tenant->id);

        return $user;
    }

    /**
     * A person OptiNexus lets into a CENTRAL tenant is a member of it here. Only a missing membership is created; one that
     * exists is left exactly as it is (an administrator's switch-off is honoured; OptiNexus-driven marks are undone by
     * reactivate()). A new member gets the tenant-wide data scope, which local assignments may narrow afterwards (an existing member's scopes are never touched).
     */
    private function ensureMembership(User $user, Tenant $tenant): void
    {
        $existing = TenantUser::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->first();

        if (! $existing) {
            TenantUser::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
            $this->audit->log('TenantUser', $user->id, 'membership_created_from_optinexus', null, ['tenant_id' => $tenant->id], $tenant->id);

            if (! DataScopeAssignment::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->exists()) {
                DataScopeAssignment::query()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'scope_type' => 'TENANT', 'scope_resource_id' => null]);
            }
        }
    }
}
