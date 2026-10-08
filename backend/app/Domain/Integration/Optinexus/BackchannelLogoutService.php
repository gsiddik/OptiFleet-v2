<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Applies a verified OptiNexus logout token (OIDC Back-Channel Logout 1.0).
 *
 *  - Every logout ends the user's API sessions (all devices; the SPA is sent
 *    back to the login page by its next 401).
 *  - With the access-revoked event the local account is also deactivated, so
 *    password login stops too: the user for scope "user", only the membership
 *    of the mapped tenant for scope "tenant". Records already inactive are
 *    left alone, so only what OptiNexus switched off is marked and can be
 *    switched on again by the next successful OptiNexus sign-in.
 */
class BackchannelLogoutService
{
    public const EVENT_BACKCHANNEL_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';

    public const EVENT_ACCESS_REVOKED = 'https://schemas.optinexus.io/event/access-revoked';

    /**
     * @param  array<string, mixed>  $claims  a verified logout token
     * @return array{matched: bool, sessions_ended: int, deactivated: string}
     */
    public function apply(array $claims): array
    {
        $user = $this->findUser($claims);
        if (! $user) {
            // Not our user (never signed in, never provisioned): nothing to do, and not an error.
            return ['matched' => false, 'sessions_ended' => 0, 'deactivated' => 'none'];
        }

        $revoked = $claims['events'][self::EVENT_ACCESS_REVOKED] ?? null;
        $tenant = null;
        if (is_array($revoked) && ($revoked['scope'] ?? 'user') === 'tenant') {
            $tenant = Tenant::query()->where('optinexus_tenant_id', $revoked['tenant_id'] ?? null)->first();
            if (! $tenant) {
                return ['matched' => true, 'sessions_ended' => 0, 'deactivated' => 'none'];
            }
        }

        $deactivated = 'none';

        DB::transaction(function () use ($user, $revoked, $tenant, &$deactivated) {
            if (! is_array($revoked)) {
                return;
            }

            if ($tenant) {
                $membership = TenantUser::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->where('status', 'active')->first();
                if ($membership) {
                    $membership->forceFill(['status' => 'inactive', 'optinexus_deactivated_at' => now()])->save();
                    $deactivated = 'membership';
                }
            } elseif ($user->status === 'active') {
                $user->forceFill(['status' => 'inactive', 'optinexus_deactivated_at' => now()])->save();
                $deactivated = 'user';
            }
        });

        return ['matched' => true, 'sessions_ended' => $this->endSessions($user, $tenant), 'deactivated' => $deactivated];
    }

    /**
     * Undo what OptiNexus deactivated, once OptiNexus has let the user in again.
     */
    public function reactivate(User $user, Tenant $tenant): void
    {
        if ($user->optinexus_deactivated_at !== null && $user->status === 'inactive') {
            $user->forceFill(['status' => 'active', 'optinexus_deactivated_at' => null])->save();
        }

        TenantUser::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)
            ->where('status', 'inactive')->whereNotNull('optinexus_deactivated_at')
            ->get()
            ->each(fn (TenantUser $membership) => $membership->forceFill(['status' => 'active', 'optinexus_deactivated_at' => null])->save());
    }

    private function endSessions(User $user, ?Tenant $tenant): int
    {
        $query = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey());

        if ($tenant) {
            $query->where('abilities', 'like', '%"tenant:'.$tenant->id.'"%');
        }

        return $query->delete();
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function findUser(array $claims): ?User
    {
        $user = User::query()->where('user_type', 'tenant')->where('optinexus_subject', $claims['sub'])->first();

        if (! $user && ! empty($claims['email'])) {
            // Account that exists here but never signed in through OptiNexus: it is not bound yet.
            $user = User::query()->where('user_type', 'tenant')->whereNull('optinexus_subject')
                ->whereRaw('lower(email) = ?', [Str::lower((string) $claims['email'])])->first();
        }

        return $user;
    }
}
