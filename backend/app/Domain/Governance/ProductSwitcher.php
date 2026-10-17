<?php

namespace App\Domain\Governance;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Governance\Exceptions\ProductNotAvailable;
use App\Domain\Governance\Exceptions\ProductSessionEnded;
use App\Models\User;

/**
 * The product switcher (audit A29/R06). What the person may open from here is exactly what OptiNexus answers to
 * POST /authorization/product-context: the products the tenant subscribes to AND the person is assigned, never the OptiNexus
 * portal, never a link this application composed from its own configuration.
 *
 *  - The list is kept at most min(ttl, 300 s) and revalidated on every render; an unreachable OptiNexus shows no products.
 *  - Navigating to another product always asks OptiNexus again (fresh answer) and only a code in that answer opens: a target
 *    named by the browser is refused (`PRODUCT_NOT_AVAILABLE`), so the browser holds no link it could replay later.
 *  - `allowed=false` means the person lost the application: the local session ends (token deleted, caches dropped).
 *  - The link is the product's `launch_url` + `tenant_hint=<OptiNexus tenant id>`; OptiNexus' own sign-in does the rest
 *    (authorization code + PKCE). No token or secret ever travels in that URL.
 */
class ProductSwitcher
{
    public const NOT_SSO_SESSION = 'not_sso_session';

    public const NOT_LINKED = 'not_linked';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** Products that are never offered as a switching target, whatever the answer says: the OptiNexus governance portal. */
    private const NEVER_LISTED = ['cgo'];

    public function __construct(
        private readonly CentralGovernance $governance,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{available: bool, reason: ?string, products: array<int, array{code: string, name: string}>, expires_at: ?int}
     *
     * @throws ProductSessionEnded when OptiNexus says the person no longer has this application
     */
    public function list(User $user, ?string $tenantId, bool $viaSso): array
    {
        if (! $viaSso || ! $tenantId) {
            return $this->unavailable(self::NOT_SSO_SESSION);
        }

        try {
            $context = $this->governance->productContext($user, $tenantId);
        } catch (GovernanceUnavailableException|GovernanceContractException) {
            return $this->unavailable(self::PROVIDER_UNAVAILABLE);
        }

        if ($context === null) {
            return $this->unavailable(self::NOT_LINKED);
        }
        $this->assertStillAllowed($user, $tenantId, $context);

        return [
            'available' => true,
            'reason' => null,
            'products' => array_map(fn (array $p) => ['code' => $p['code'], 'name' => $p['name']], $this->targets($context)),
            'expires_at' => $context['expires_at'],
        ];
    }

    /**
     * The URL to send the browser to. Always checked against a fresh answer.
     *
     * @return array{code: string, url: string}
     *
     * @throws ProductNotAvailable
     * @throws ProductSessionEnded
     * @throws GovernanceUnavailableException when OptiNexus cannot be asked (nothing opens)
     */
    public function launch(User $user, ?string $tenantId, bool $viaSso, string $code): array
    {
        $context = $viaSso && $tenantId ? $this->governance->productContext($user, $tenantId, fresh: true) : null;
        if ($context === null) {
            $this->refused($user, $tenantId, $code, $viaSso ? self::NOT_LINKED : self::NOT_SSO_SESSION);
            throw new ProductNotAvailable;
        }
        $this->assertStillAllowed($user, $tenantId, $context);

        $target = collect($this->targets($context))->first(fn (array $p) => strcasecmp($p['code'], $code) === 0);
        if ($target === null) {
            $this->refused($user, $tenantId, $code, 'not_offered');
            throw new ProductNotAvailable;
        }

        $this->audit->log('product', $target['code'], 'product.launched', null, ['code' => $target['code'], 'actor_subject' => $user->optinexus_subject], $tenantId);

        return ['code' => $target['code'], 'url' => $target['url']];
    }

    /**
     * Products this person may be sent to, with the final URL. Anything that is not a plain http(s) link, or leads back here
     * or to the OptiNexus portal, is left out.
     *
     * @return array<int, array{code: string, name: string, url: string}>
     */
    public function targets(array $context): array
    {
        $own = strtolower((string) config('optinexus.governance.application_code', 'optifleet'));
        $ownOrigin = $this->origin((string) config('optinexus.sso.frontend_url'));
        $portalOrigin = $this->origin((string) config('optinexus.base_url'));
        $targets = [];

        foreach ($context['products'] as $product) {
            $code = strtolower($product['code']);
            if ($product['current'] || $code === $own || in_array($code, self::NEVER_LISTED, true) || isset($targets[$code])) {
                continue;
            }
            $url = $this->launchUrl($product['launch_url'], $context['nexus_tenant_id']);
            if ($url === null) {
                continue;
            }
            $origin = $this->origin($url);
            if ($origin === $ownOrigin || $origin === $portalOrigin) {
                continue;
            }
            $targets[$code] = ['code' => $product['code'], 'name' => $product['name'], 'url' => $url];
        }

        return array_values($targets);
    }

    /** launch_url + tenant_hint; null when the address is not something a browser should be sent to. */
    private function launchUrl(?string $launchUrl, string $nexusTenantId): ?string
    {
        if (! $launchUrl || strlen($launchUrl) > 2048) {
            return null;
        }
        $parts = parse_url($launchUrl);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $secureOnly = ! app()->environment('local', 'testing');
        if (! in_array($scheme, $secureOnly ? ['https'] : ['https', 'http'], true)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $query['tenant_hint'] = $nexusTenantId;

        return $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').'?'.http_build_query($query);
    }

    private function origin(string $url): ?string
    {
        $p = parse_url($url);
        if (! is_array($p) || ! isset($p['scheme'], $p['host'])) {
            return null;
        }

        return strtolower($p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : ''));
    }

    /** @throws ProductSessionEnded */
    private function assertStillAllowed(User $user, ?string $tenantId, array $context): void
    {
        if ($context['allowed']) {
            return;
        }

        // OptiNexus no longer lets this person use the application: the token cannot be replayed and the cached answers go.
        $user->currentAccessToken()?->delete();
        $this->governance->forgetUser($user, $tenantId);

        throw new ProductSessionEnded($context['deny_reason']);
    }

    private function refused(User $user, ?string $tenantId, string $code, string $why): void
    {
        $this->audit->log('product', substr($code, 0, 64), 'product.launch_refused', null, ['code' => substr($code, 0, 64), 'why' => $why, 'actor_subject' => $user->optinexus_subject], $tenantId);
    }

    /** @return array{available: bool, reason: string, products: array<int, never>, expires_at: null} */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason, 'products' => [], 'expires_at' => null];
    }
}
