<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Identity\Models\Tenant;

/**
 * Who may read a tenant's accounting feed (audit A19). A valid credential is not enough: while pairing is enforced the credential
 * must have been issued for the OptiNexus tenant this tenant is linked to now, and OptiNexus must say that tenant is ACTIVE, has a
 * live subscription, is entitled to this application AND to the consumer application, and holds the consumer's connector
 * capabilities. The tenant is never taken from the request; the OptiNexus identity comes from the tenant row and the credential.
 *
 * Only reading the feed is gated. Recording invoices, the outbox and the projection of feed events never look at any of this, so an
 * unsubscribed or revoked consumer does not stop Fleet's own transactions (the events wait in the feed for the consumer's gap policy).
 *
 * The answer of OptiNexus is cached for at most `optinexus.governance.ttl_seconds` (300 s); a revoked credential is refused at once.
 */
class FeedPairingGate
{
    public function __construct(private readonly CentralGovernance $governance) {}

    public function enforced(): bool
    {
        return config('optinexus.feed.pairing') === 'required' || (bool) config('optinexus.enabled');
    }

    public function check(Tenant $tenant, AccountingFeedCredential $credential): FeedPairing
    {
        if (! $this->enforced()) {
            return FeedPairing::notEnforced();
        }

        $nexusTenant = $tenant->optinexus_tenant_id;
        if (! $nexusTenant) {
            return FeedPairing::denied(403, 'FEED_TENANT_NOT_PAIRED', 'The tenant is not linked to an OptiNexus tenant.');
        }
        if (! $credential->nexus_tenant_id) {
            return FeedPairing::denied(403, 'FEED_CREDENTIAL_UNPAIRED', 'The credential was issued without an OptiNexus pairing; rotate it.');
        }
        if (! hash_equals(strtolower($credential->nexus_tenant_id), strtolower($nexusTenant))) {
            return FeedPairing::denied(403, 'FEED_PAIRING_CHANGED', 'The credential was issued for another OptiNexus tenant; rotate it.');
        }

        try {
            $commercial = $this->governance->commercial($tenant->id);
        } catch (GovernanceUnavailableException $e) {
            return FeedPairing::denied(503, 'FEED_GOVERNANCE_UNAVAILABLE', 'The authorization service (OptiNexus) is not available; keep the cursor and retry.', $e->retryAfterSeconds ?? 30);
        } catch (GovernanceContractException) {
            return FeedPairing::denied(503, 'FEED_GOVERNANCE_UNAVAILABLE', 'The authorization service (OptiNexus) gave an unusable answer; keep the cursor and retry.', 60);
        }

        if (($commercial['tenant_status'] ?? null) !== 'ACTIVE') {
            return FeedPairing::denied(403, 'FEED_TENANT_NOT_ACTIVE', 'The tenant is not active in OptiNexus.');
        }
        if (($commercial['live_subscriptions'] ?? 0) < 1) {
            return FeedPairing::denied(403, 'FEED_SUBSCRIPTION_INACTIVE', 'The tenant has no active subscription in OptiNexus.');
        }

        $applications = array_map('strtolower', $commercial['applications'] ?? []);
        $own = strtolower((string) config('optinexus.governance.application_code'));
        $consumer = strtolower((string) ($credential->consumer_application ?: config('optinexus.feed.consumer_application')));
        if (! in_array($own, $applications, true)) {
            return FeedPairing::denied(403, 'FEED_PRODUCER_NOT_ENTITLED', 'The tenant is not subscribed to the producing application.');
        }
        if (! in_array($consumer, $applications, true)) {
            return FeedPairing::denied(403, 'FEED_CONSUMER_NOT_ENTITLED', 'The tenant is not subscribed to the consuming application.');
        }

        $capabilities = $commercial['capabilities'] ?? [];
        $required = (array) config('optinexus.feed.consumer_capabilities');
        if ($ownCapability = config('optinexus.feed.own_capability')) {
            $required[] = (string) $ownCapability;
        }
        $missing = array_values(array_diff($required, $capabilities));
        if ($missing !== []) {
            return FeedPairing::denied(403, 'FEED_CONNECTOR_NOT_ENTITLED', 'The tenant does not hold the connector capability: '.implode(', ', $missing).'.');
        }

        return FeedPairing::allowed($nexusTenant, $own, $consumer);
    }
}
