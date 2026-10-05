<?php

namespace App\Domain\Configuration\Services;

/**
 * Section 3/4: whitelist-only token validation — never eval()/interpolate
 * arbitrary PHP. A format string may only contain literal characters plus
 * the tokens below; {SEQ} may optionally carry an explicit padding as
 * {SEQ:N}.
 */
class NumberingFormatValidator
{
    // ITEMTYPE / CG: Product SKU context tokens (Item Type short code, Component Group
    // abbreviation) — resolved only when the caller supplies them (product_sku), empty elsewhere.
    public const ALLOWED_TOKENS = ['DOC', 'TENANT', 'BRANCH', 'WORKSHOP', 'WAREHOUSE', 'YYYY', 'YY', 'MMMM', 'MMM', 'MM', 'DD', 'ITEMTYPE', 'CG'];

    /** Optional Initials (owner decision: override the entity code when filled). */
    public const INITIAL_KEYS = ['tenant_initial', 'branch_initial', 'workshop_initial', 'warehouse_initial'];

    public const MAX_SEQUENCE_DIGITS = 12;
    private const ALLOWED_RESET_RULES = ['NEVER', 'YEARLY', 'MONTHLY', 'DAILY'];

    public function validate(array $payload): void
    {
        if (empty($payload['format']) || ! is_string($payload['format'])) {
            throw new NumberingException('Numbering format is required.');
        }
        if (! preg_match('/\{SEQ(:\d+)?\}/', $payload['format'])) {
            throw new NumberingException('Numbering format must include a {SEQ} or {SEQ:N} sequence token.');
        }

        preg_match_all('/\{([A-Z]+)(:(\d+))?\}/', $payload['format'], $matches);
        foreach ($matches[1] as $i => $token) {
            if ($token !== 'SEQ' && ! in_array($token, self::ALLOWED_TOKENS, true)) {
                throw new NumberingException("Unknown numbering token: {{$token}}.");
            }
            if ($matches[2][$i] !== '' && ($token !== 'SEQ' || (int) $matches[3][$i] < 1 || (int) $matches[3][$i] > self::MAX_SEQUENCE_DIGITS)) {
                throw new NumberingException($token === 'SEQ'
                    ? 'Sequential Digit must be a whole number between 1 and '.self::MAX_SEQUENCE_DIGITS.'.'
                    : "Token {{$token}} does not take a number.");
            }
        }
        // Braces only ever delimit tokens: a stray "{" or "}" is a malformed token.
        if (preg_match('/[{}]/', preg_replace('/\{[A-Z]+(:\d+)?\}/', '', $payload['format']))) {
            throw new NumberingException('The format contains an incomplete or malformed token.');
        }
        foreach ([...self::INITIAL_KEYS, 'doc_code'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                continue;
            }
            if (! is_string($payload[$key]) || mb_strlen($payload[$key]) > 30 || preg_match('/[{}]/', $payload[$key])) {
                throw new NumberingException(str_replace('_', ' ', ucfirst($key)).' must be text of at most 30 characters without braces.');
            }
        }

        $resetRule = $payload['reset_rule'] ?? 'NEVER';
        if (! in_array($resetRule, self::ALLOWED_RESET_RULES, true)) {
            throw new NumberingException("Invalid reset_rule: {$resetRule}.");
        }

        if (isset($payload['sequence_start']) && (! is_int($payload['sequence_start']) || $payload['sequence_start'] < 1)) {
            throw new NumberingException('sequence_start must be a positive integer.');
        }
        if (isset($payload['sequence_padding']) && (! is_int($payload['sequence_padding']) || $payload['sequence_padding'] < 1 || $payload['sequence_padding'] > 12)) {
            throw new NumberingException('sequence_padding must be an integer between 1 and 12.');
        }
    }
}
