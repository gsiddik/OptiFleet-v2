<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Turns verified OptiNexus claims into a local OptiFleet session.
 *
 * Authorization stays local: the tenant is taken from the verified
 * `tenant_id` claim only, the user must already exist here (matched on the
 * stable OptiNexus subject, else on a verified e-mail) with an active
 * membership of that tenant, and the user keeps their local roles and data
 * scope. OptiNexus decides *who may enter*; OptiFleet decides *what they may do*.
 */
class SsoLoginService
{
    public function __construct(private readonly BackchannelLogoutService $lifecycle) {}

    /**
     * @param  array<string, mixed>  $claims
     * @return string one-time ticket the SPA exchanges for a session token
     *
     * @throws SsoException
     */
    public function ticketFor(array $claims): string
    {
        [$user, $tenant] = $this->resolve($claims);

        $ticket = Str::random(48);
        Cache::put(
            'optinexus.sso.ticket.'.hash('sha256', $ticket),
            ['user_id' => $user->id, 'tenant_id' => $tenant->id, 'apps' => $claims['apps'] ?? []],
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

        return ['user' => $user, 'tenant' => $tenant, 'apps' => $data['apps']];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{0: User, 1: Tenant}
     *
     * @throws SsoException
     */
    private function resolve(array $claims): array
    {
        $tenant = Tenant::query()->where('optinexus_tenant_id', $claims['tenant_id'] ?? null)->first();
        if (! $tenant) {
            throw new SsoException('tenant_not_linked');
        }
        if ($tenant->status !== 'ACTIVE') {
            throw new SsoException('tenant_inactive');
        }

        $user = User::query()->where('optinexus_subject', $claims['sub'])->first();

        if (! $user) {
            if (empty($claims['email']) || empty($claims['email_verified'])) {
                throw new SsoException('email_not_verified');
            }

            $user = User::query()->whereRaw('lower(email) = ?', [Str::lower($claims['email'])])->first();
            if (! $user) {
                throw new SsoException('user_not_provisioned');
            }
            if ($user->optinexus_subject !== null) {
                // Same e-mail but already bound to a different OptiNexus identity: never re-bind.
                throw new SsoException('account_conflict');
            }
            if ($user->user_type !== 'tenant') {
                throw new SsoException('user_not_provisioned');
            }

            $user->forceFill(['optinexus_subject' => $claims['sub']])->save();
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
}
