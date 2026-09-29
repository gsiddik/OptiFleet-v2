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
    private const ALLOWED_TOKENS = ['DOC', 'TENANT', 'BRANCH', 'WORKSHOP', 'WAREHOUSE', 'YYYY', 'YY', 'MM', 'DD', 'ITEMTYPE', 'CG'];
    private const ALLOWED_RESET_RULES = ['NEVER', 'YEARLY', 'MONTHLY', 'DAILY'];

    public function validate(array $payload): void
    {
        if (empty($payload['format']) || ! is_string($payload['format'])) {
            throw new NumberingException('Numbering format is required.');
        }
        if (! preg_match('/\{SEQ(:\d+)?\}/', $payload['format'])) {
            throw new NumberingException('Numbering format must include a {SEQ} or {SEQ:N} sequence token.');
        }

        preg_match_all('/\{([A-Z]+)(:\d+)?\}/', $payload['format'], $matches);
        foreach ($matches[1] as $token) {
            if ($token !== 'SEQ' && ! in_array($token, self::ALLOWED_TOKENS, true)) {
                throw new NumberingException("Unknown numbering token: {{$token}}.");
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
