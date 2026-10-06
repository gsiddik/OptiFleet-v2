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

    /** The first supported primary language of an Accept-Language list (e.g. ['id-ID', 'en'] → id). */
    public static function fromLanguages(array $languages): ?string
    {
        foreach ($languages as $language) {
            $primary = strtolower((string) strtok(str_replace('_', '-', (string) $language), '-'));
            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }
}
