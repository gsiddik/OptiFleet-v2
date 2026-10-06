<?php

namespace App\Domain\DocumentGeneration\Support;

/**
 * Document language priority (owner decision D1): explicit Print/Export choice → user preferred locale →
 * tenant default locale → system fallback en. Unsupported values are ignored at each level.
 */
final class DocumentLocale
{
    public const SUPPORTED = ['en', 'id'];

    public const FALLBACK = 'en';

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::SUPPORTED, true);
    }

    public static function resolve(?string $explicit, ?string $userPreferred, ?string $tenantDefault): string
    {
        foreach ([$explicit, $userPreferred, $tenantDefault] as $candidate) {
            if (self::isSupported($candidate)) {
                return $candidate;
            }
        }

        return self::FALLBACK;
    }
}
