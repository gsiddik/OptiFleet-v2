<?php

namespace Database\Seeders;

/**
 * Demo tire serial numbers: 20 characters of letters, digits and dashes (XXXX-XXXX-XXXX-XXXXX),
 * random-looking but deterministic per key, so re-seeding finds the same tire. A seeding pattern
 * only — never a production validation rule.
 */
final class DemoSerial
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(string $key): string
    {
        $hash = hash('sha256', 'optifleet-demo-serial:'.$key);
        $chars = '';
        for ($i = 0; strlen($chars) < 17; $i += 2) {
            $chars .= self::ALPHABET[hexdec(substr($hash, $i, 2)) % strlen(self::ALPHABET)];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4, 4).'-'.substr($chars, 8, 4).'-'.substr($chars, 12, 5);
    }
}
