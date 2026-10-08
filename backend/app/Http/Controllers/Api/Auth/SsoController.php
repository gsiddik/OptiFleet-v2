<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Integration\Optinexus\BackchannelLogoutService;
use App\Domain\Integration\Optinexus\OptinexusOidcClient;
use App\Domain\Integration\Optinexus\SsoException;
use App\Domain\Integration\Optinexus\SsoLoginService;
use App\Http\Controllers\Controller;
use App\Http\Support\CurrentUserPresenter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Sign in with OptiNexus". Password login stays available; this is an
 * additional path that is off unless OPTINEXUS_ENABLED is true.
 *
 * Browser flow: GET /auth/sso/redirect -> OptiNexus -> GET /auth/sso/callback
 * -> SPA /sso/callback?ticket=... -> POST /auth/sso/exchange (ticket -> token).
 * The access token never travels in a URL; the one-time ticket lives 60 s.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly OptinexusOidcClient $oidc,
        private readonly SsoLoginService $login,
        private readonly CurrentUserPresenter $presenter,
        private readonly TenantContext $context,
        private readonly BackchannelLogoutService $lifecycle,
    ) {}

    public function status()
    {
        return $this->ok(['enabled' => $this->oidc->enabled()]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->oidc->enabled()) {
            return $this->failure('sso_disabled');
        }

        return redirect()->away($this->oidc->authorizationUrl($request->query('tenant_hint')));
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->oidc->enabled()) {
            return $this->failure('sso_disabled');
        }

        if ($request->query('error')) {
            return $this->failure($request->query('error') === 'access_denied' ? 'access_denied' : 'sso_failed');
        }

        $code = (string) $request->query('code');
        $state = (string) $request->query('state');
        if ($code === '' || $state === '') {
            return $this->failure('sso_failed');
        }

        try {
            $ticket = $this->login->ticketFor($this->oidc->completeLogin($code, $state));
        } catch (SsoException $e) {
            return $this->failure($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->failure('sso_failed');
        }

        return redirect()->away(config('optinexus.sso.frontend_url').'/sso/callback?'.http_build_query(['ticket' => $ticket]));
    }

    public function exchange(Request $request)
    {
        $data = $request->validate(['ticket' => ['required', 'string', 'max:128']]);

        $session = $this->login->redeemTicket($data['ticket']);
        abort_unless($session, 422, 'This sign-in link is invalid or has expired. Please sign in again.');

        ['user' => $user, 'tenant' => $tenant, 'apps' => $apps] = $session;

        $token = $user->createToken('tenant-session', ['tenant:'.$tenant->id])->plainTextToken;
        $this->context->setTenantId($tenant->id);
        $this->context->setUser($user);

        return $this->ok([
            'token' => $token,
            'user' => $this->presenter->present($user),
            'sso' => ['tenant_id' => $tenant->id, 'apps' => $apps, 'logout_url' => $this->oidc->logoutUrl()],
        ]);
    }

    /**
     * OIDC Back-Channel Logout 1.0 receiver. Called by the OptiNexus server (no
     * browser, no bearer token): authenticity comes from the signed logout token.
     */
    public function backchannelLogout(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];

        if (! $this->oidc->enabled()) {
            return response()->json(['error' => 'sso_disabled'], 400, $headers);
        }

        try {
            $claims = $this->oidc->verifyLogoutToken((string) $request->input('logout_token'));
        } catch (SsoException $e) {
            return response()->json(['error' => 'invalid_request', 'error_description' => $e->getMessage()], 400, $headers);
        }

        $this->lifecycle->apply($claims);

        return response()->json((object) [], 200, $headers);
    }

    private function failure(string $code): RedirectResponse
    {
        return redirect()->away(config('optinexus.sso.frontend_url').'/login?'.http_build_query(['sso_error' => $code]));
    }
}
