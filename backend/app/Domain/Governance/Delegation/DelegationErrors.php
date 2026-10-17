<?php

namespace App\Domain\Governance\Delegation;

/**
 * Every refusal the OptiNexus delegation API documents (GOVERNANCE_CONTRACTS.md section 2), mapped to the HTTP status and the
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
        'RESOURCE_NOT_FOUND' => [404, 'errors.delegation.notFound'],
        'VALIDATION_ERROR' => [422, 'errors.delegation.validation'],
    ];

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
     * @param  array{status: int, error: ?array{code: ?string, message: ?string, details: mixed}, correlation_id: string}  $response
     */
    public static function fromResponse(array $response, bool $mutation): DelegationException
    {
        $code = (string) ($response['error']['code'] ?? '');
        $details = $response['error']['details'] ?? null;

        if (isset(self::MAP[$code])) {
            [$status, $key] = self::MAP[$code];

            return new DelegationException($code, $status, $key, $code === 'VALIDATION_ERROR' && is_array($details) ? self::fieldErrors($details) : null,
                $response['correlation_id'], retryable: $status === 503, details: $code === 'PERMISSION_NOT_DELEGABLE' || $code === 'ROLE_NOT_ASSIGNABLE' ? $details : null);
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
