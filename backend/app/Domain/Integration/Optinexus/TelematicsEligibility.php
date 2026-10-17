<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Identity\Models\Tenant;

/**
 * Whether a tenant's telematics feed may be requested from the OptiNexus gateway (audit A10). All must hold: OptiNexus is enabled,
 * the tenant is ACTIVE and linked, the tenant switched the feed on (activation), and OptiNexus says the tenant is ACTIVE with a live
 * subscription that includes the telematics application. Otherwise nothing is requested for the tenant and the reason is recorded.
 *
 * The OptiNexus answer is the commercial context read through CentralGovernance (cached at most 300 s, dropped on failure); an
 * unreachable or unusable answer is its own state (`provider_unavailable`), never treated as "subscribed" and never as "not".
 */
class TelematicsEligibility
{
    public const ELIGIBLE = 'eligible';

    public const DISABLED = 'disabled';

    public const NOT_LINKED = 'not_linked';

    public const TENANT_INACTIVE = 'tenant_inactive';

    public const NOT_ACTIVATED = 'not_activated';

    public const NOT_SUBSCRIBED = 'not_subscribed';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public function __construct(private readonly CentralGovernance $governance) {}

    /** States in which the tenant is simply not using the feed: not a failure of anything. */
    public static function isSkip(string $state): bool
    {
        return in_array($state, [self::DISABLED, self::NOT_LINKED, self::TENANT_INACTIVE, self::NOT_ACTIVATED, self::NOT_SUBSCRIBED], true);
    }

    /**
     * @return array{state: string, code: ?string, message: ?string}
     */
    public function check(Tenant $tenant): array
    {
        if (! config('optinexus.enabled')) {
            return $this->result(self::DISABLED);
        }
        if ($tenant->status !== 'ACTIVE') {
            return $this->result(self::TENANT_INACTIVE);
        }
        if (! $tenant->optinexus_tenant_id) {
            return $this->result(self::NOT_LINKED);
        }
        if ($tenant->telematics_activated_at === null) {
            return $this->result(self::NOT_ACTIVATED);
        }

        try {
            $commercial = $this->governance->commercial($tenant->id);
        } catch (GovernanceUnavailableException $e) {
            return $this->result(self::PROVIDER_UNAVAILABLE, 'GOVERNANCE_UNAVAILABLE', $e->getMessage());
        } catch (GovernanceContractException $e) {
            return $this->result(self::PROVIDER_UNAVAILABLE, $e->errorCode ?? 'GOVERNANCE_CONTRACT', $e->getMessage());
        }

        $applications = array_map('strtolower', $commercial['applications'] ?? []);
        if (($commercial['tenant_status'] ?? null) !== 'ACTIVE'
            || ($commercial['live_subscriptions'] ?? 0) < 1
            || ! in_array(strtolower((string) config('optinexus.telematics.application_code')), $applications, true)) {
            return $this->result(self::NOT_SUBSCRIBED);
        }

        return $this->result(self::ELIGIBLE);
    }

    /** @return array{state: string, code: ?string, message: ?string} */
    private function result(string $state, ?string $code = null, ?string $message = null): array
    {
        return ['state' => $state, 'code' => $code, 'message' => $message !== null ? mb_substr($message, 0, 300) : null];
    }
}
