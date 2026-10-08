<?php

namespace App\Domain\Integration\Optinexus;

/**
 * Verifies RS256 JWTs against a JWKS, using ext-openssl only (no JWT
 * library is installed). Only the claims-independent part lives here:
 * signature + algorithm + key lookup. Issuer, audience, expiry and nonce
 * are checked by the caller.
 */
class JwtRs256Verifier
{
    /**
     * @param  array{keys: array<int, array<string, string>>}  $jwks
     * @return array<string, mixed>|null the claims when the signature is valid
     */
    public function verify(string $jwt, array $jwks): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$h, $p, $s] = $parts;
        $header = json_decode(self::decode($h), true);
        $claims = json_decode(self::decode($p), true);

        // Pin the algorithm: never trust the token to pick "none" or HS256.
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256') {
            return null;
        }

        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (($jwk['kty'] ?? null) !== 'RSA' || (isset($header['kid'], $jwk['kid']) && $header['kid'] !== $jwk['kid'])) {
                continue;
            }

            $key = openssl_pkey_get_public($this->pem($jwk['n'], $jwk['e']));
            if ($key && openssl_verify("{$h}.{$p}", self::decode($s), $key, OPENSSL_ALGO_SHA256) === 1) {
                return $claims;
            }
        }

        return null;
    }

    private function pem(string $n, string $e): string
    {
        $rsaPublicKey = $this->seq($this->int(self::decode($n)).$this->int(self::decode($e)));
        $algorithm = $this->seq("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
        $spki = $this->seq($algorithm."\x03".$this->len(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->len(strlen($bytes)).$bytes;
    }

    private function seq(string $content): string
    {
        return "\x30".$this->len(strlen($content)).$content;
    }

    private function len(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
