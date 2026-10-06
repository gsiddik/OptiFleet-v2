/**
 * Application locale (i18n structural preparation).
 *
 * Presentation formatting (dates, month names, numbers) takes an explicit locale instead of relying on
 * the browser's. Until the i18n rollout adds locale resolution (user preference → tenant default → en),
 * the app runs in the system fallback `en`, so output is unchanged.
 */
export type AppLocale = 'en' | 'id';

export const SUPPORTED_LOCALES: readonly AppLocale[] = ['en', 'id'];

/** System fallback locale. */
export const DEFAULT_LOCALE: AppLocale = 'en';

const INTL_TAG: Readonly<Record<AppLocale, string>> = { en: 'en-US', id: 'id-ID' };

let current: AppLocale = DEFAULT_LOCALE;

export function appLocale(): AppLocale {
  return current;
}

/** Sets the active locale; unsupported values fall back to `en`. Not wired to any UI yet. */
export function setAppLocale(locale: string | null | undefined): AppLocale {
  current = SUPPORTED_LOCALES.includes(locale as AppLocale) ? (locale as AppLocale) : DEFAULT_LOCALE;
  return current;
}

/** BCP 47 tag for `Intl` APIs. */
export function intlTag(locale: AppLocale = appLocale()): string {
  return INTL_TAG[locale];
}

const monthCache = new Map<string, readonly string[]>();

/** Month names for the locale, January first (cached per locale and style). */
export function monthNames(style: 'long' | 'short' = 'long', locale: AppLocale = appLocale()): readonly string[] {
  const cacheKey = `${locale}:${style}`;
  let names = monthCache.get(cacheKey);
  if (!names) {
    const fmt = new Intl.DateTimeFormat(intlTag(locale), { month: style, timeZone: 'UTC' });
    names = Array.from({ length: 12 }, (_, i) => fmt.format(Date.UTC(2000, i, 15)));
    monthCache.set(cacheKey, names);
  }
  return names;
}
