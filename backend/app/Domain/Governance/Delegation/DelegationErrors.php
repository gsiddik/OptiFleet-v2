<?php

namespace App\Domain\Governance\Delegation;

/**
 * Every refusal the OptiNexus delegation API documents (GOVERNANCE_CONTRACTS.md sections 2 and 2b), mapped to the HTTP status and the
 * translated message OptiFleet answers with. The code itself stays the English OptiNexus code (the UI branches on it, the
 * audit trail stores it); only the message is translated. Anything not listed is an unexpected answer (HTTP 502).
 */
final class DelegationErrors
{
    /** @var array<string, array{0: int, 1: string}> code => [HTTP status, message key] */
    public const MAP = [
        'ACTOR_NOT_TENANT_ADMIN' => [403, 'errors.delegation.actorNotTenantAdmin'],
        'PERMISSION_NOT_DELEGABLE' => [422, 'errors.delegation.permissionNotDelegable'],
        'ROLE_NOT_ASSIGNABLE' => [422, 'errors.delegation.roleNotAssignable'],
        'ROLE_NOT_EDITABLE' => [404, 'errors.delegation.roleNotEditable'],
        'ROLE_IN_USE' => [409, 'errors.delegation.roleInUse'],
        'USER_NOT_IN_TENANT' => [404, 'errors.delegation.userNotInTenant'],
        'TENANT_ADMIN_PROTECTED' => [409, 'errors.delegation.tenantAdminProtected'],
        'IDENTITY_NOT_LINKABLE' => [409, 'errors.delegation.identityNotLinkable'],
        'MEMBERSHIP_NOT_ACTIVE' => [409, 'errors.delegation.membershipNotActive'],
        'SELF_REVOCATION_DENIED' => [409, 'errors.delegation.selfRevocationDenied'],
        'APPLICATION_NOT_SUBSCRIBED' => [403, 'errors.delegation.applicationNotSubscribed'],
        'TENANT_NOT_ACTIVE' => [403, 'errors.delegation.tenantNotActive'],
        'APPLICATION_NOT_ACTIVE' => [403, 'errors.delegation.applicationNotActive'],
        // Configuration problems of OptiFleet's own service account: nothing the signed-in user can fix.
        'TENANT_MISMATCH' => [503, 'errors.delegation.misconfigured'],
        'SERVICE_ACCOUNT_NOT_BOUND' => [503, 'errors.delegation.misconfigured'],
        'APPLICATION_NOT_DELEGABLE' => [503, 'errors.delegation.misconfigured'],
        'INSUFFICIENT_SCOPE' => [503, 'errors.delegation.misconfigured'],
        'FORBIDDEN' => [503, 'errors.delegation.misconfigured'],
        // Invitations (section 2b). The 429 pair carries the wait OptiNexus asks for (Retry-After).
        'INVITATION_PENDING' => [409, 'errors.delegation.invitationPending'],
        'ALREADY_MEMBER' => [409, 'errors.delegation.alreadyMember'],
        'INVITATION_NOT_FOUND' => [404, 'errors.delegation.invitationNotFound'],
        'INVITATION_NOT_PENDING' => [409, 'errors.delegation.invitationNotPending'],
        'INVITATION_RESEND_COOLDOWN' => [429, 'errors.delegation.invitationResendCooldown'],
        'INVITATION_RESEND_LIMIT' => [409, 'errors.delegation.invitationResendLimit'],
        'INVITATION_RATE_LIMITED' => [429, 'errors.delegation.invitationRateLimited'],
        // The deprecated direct creation switched off by the deployment (NEXUS_DELEGATION_DIRECT_USERS=false).
        'DIRECT_USER_CREATION_DISABLED' => [410, 'errors.delegation.directCreationDisabled'],
        'RESOURCE_NOT_FOUND' => [404, 'errors.delegation.notFound'],
        'VALIDATION_ERROR' => [422, 'errors.delegation.validation'],
    ];

    /** Refusals whose `details` are passed on: the UI needs them (offending role ids, the open invitation, the status that blocks). */
    private const WITH_DETAILS = ['PERMISSION_NOT_DELEGABLE', 'ROLE_NOT_ASSIGNABLE', 'INVITATION_PENDING', 'INVITATION_NOT_PENDING'];

    /** Raised before any call is made. */
    public const NOT_CENTRAL = ['NOT_CENTRALLY_GOVERNED', 409, 'errors.delegation.notCentral'];

    public const IDENTITY_NOT_LINKED = ['IDENTITY_NOT_LINKED', 403, 'errors.http.identityNotLinked'];

    public const UNAVAILABLE = ['DELEGATION_UNAVAILABLE', 503, 'errors.delegation.unavailable'];

    /** The same outage when a change was in flight: it may have been applied. */
    public const UNAVAILABLE_CHANGE = 'errors.delegation.unavailableChange';

    public const UNEXPECTED = ['DELEGATION_UNEXPECTED', 502, 'errors.delegation.unexpected'];

    /** @return list<string> every code the contract documents plus the ones OptiFleet raises itself */
    public static function codes(): array
    {
        return array_merge(array_keys(self::MAP), [self::NOT_CENTRAL[0], self::IDENTITY_NOT_LINKED[0], self::UNAVAILABLE[0], self::UNEXPECTED[0]]);
    }

    /** @return list<string> every message key used, for the translation completeness test */
    public static function messageKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_column(self::MAP, 1),
            [self::NOT_CENTRAL[2], self::IDENTITY_NOT_LINKED[2], self::UNAVAILABLE[2], self::UNAVAILABLE_CHANGE, self::UNEXPECTED[2]],
        )));
    }

    /**
     * @param  array{status: int, error: ?array{code: ?string, message: ?string, details: mixed}, correlation_id: string, retry_after?: ?int}  $response
     */
    public static function fromResponse(array $response, bool $mutation): DelegationException
    {
        $code = (string) ($response['error']['code'] ?? '');
        $details = $response['error']['details'] ?? null;

        if (isset(self::MAP[$code])) {
            [$status, $key] = self::MAP[$code];

            return new DelegationException($code, $status, $key, $code === 'VALIDATION_ERROR' && is_array($details) ? self::fieldErrors($details) : null,
                $response['correlation_id'], retryable: $status === 503 || $status === 429, details: in_array($code, self::WITH_DETAILS, true) ? self::safeDetails($details) : null,
                retryAfterSeconds: $status === 429 ? ($response['retry_after'] ?? null) : null);
        }

        // 401 / 403 without a documented code: the service account is not accepted (expired credential, wrong scope).
        if (in_array($response['status'], [401, 403], true)) {
            return new DelegationException($code !== '' ? $code : 'DELEGATION_FORBIDDEN', 503, 'errors.delegation.misconfigured', null, $response['correlation_id'], retryable: true);
        }

        [$unexpectedCode, $status, $key] = self::UNEXPECTED;

        return new DelegationException($unexpectedCode, $status, $key, null, $response['correlation_id'], outcomeUnknown: $mutation);
    }

    public static function unavailable(string $correlationId, bool $mutation): DelegationException
    {
        [$code, $status, $key] = self::UNAVAILABLE;

        return new DelegationException($code, $status, $mutation ? self::UNAVAILABLE_CHANGE : $key, null, $correlationId, retryable: true, outcomeUnknown: $mutation);
    }

    public static function notCentral(): DelegationException
    {
        [$code, $status, $key] = self::NOT_CENTRAL;

        return new DelegationException($code, $status, $key);
    }

    public static function identityNotLinked(): DelegationException
    {
        [$code, $status, $key] = self::IDENTITY_NOT_LINKED;

        return new DelegationException($code, $status, $key);
    }

    /**
     * Details of a refusal go to the browser: only scalars and lists of scalars, so nothing unexpected (a token, an object) rides along.
     *
     * @return array<string, mixed>|null
     */
    private static function safeDetails(mixed $details): ?array
    {
        if (! is_array($details)) {
            return null;
        }
        $out = [];
        foreach ($details as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $out[$key] = $value;
            } elseif (is_string($key) && is_array($value) && array_is_list($value) && array_filter($value, fn ($v) => ! is_scalar($v)) === []) {
                $out[$key] = $value;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<mixed>  $details
     * @return array<string, array<int, string>>|null
     */
    private static function fieldErrors(array $details): ?array
    {
        $out = [];
        foreach ($details as $field => $messages) {
            if (is_string($field)) {
                $out[$field] = array_values(array_filter(array_map('strval', (array) $messages)));
            }
        }

        return $out === [] ? null : $out;
    }
}
