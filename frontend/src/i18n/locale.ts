/**
 * Application locale (i18n structural preparation).
 *
 * Locale codes are `en` / `id` (never display names). Presentation formatting (dates, month names,
 * numbers) takes an explicit locale instead of relying on the browser's.
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

/** Sets the active locale; unsupported values fall back to `en`. Use i18n `changeLocale()` to switch the UI. */
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

const isSupported = (value: string | null | undefined): value is AppLocale => !!value && SUPPORTED_LOCALES.includes(value as AppLocale);

/** First supported primary language among the browser's preferences (e.g. `id-ID` → `id`). */
export function browserLocale(languages: readonly string[] = typeof navigator === 'undefined' ? [] : navigator.languages ?? [navigator.language]): AppLocale | null {
  for (const tag of languages) {
    const primary = tag?.toLowerCase().split(/[-_]/)[0];
    if (isSupported(primary)) return primary;
  }
  return null;
}

/**
 * UI language: the user's preferred locale, then the tenant default, then the browser, then English.
 * Same order as the backend (ResolveRequestLocale), which receives the browser step as Accept-Language.
 */
export function resolveUiLocale(input: { preferred?: string | null; tenantDefault?: string | null; browser?: readonly string[] }): AppLocale {
  if (isSupported(input.preferred)) return input.preferred;
  if (isSupported(input.tenantDefault)) return input.tenantDefault;
  return browserLocale(input.browser) ?? DEFAULT_LOCALE;
}

const STORAGE_KEY = 'optifleet_locale';

/** The last resolved locale, applied before the first render so the UI does not flash another language. */
export function cachedLocale(): AppLocale | null {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return isSupported(value) ? value : null;
  } catch {
    return null;
  }
}

export function cacheLocale(locale: AppLocale): void {
  try {
    localStorage.setItem(STORAGE_KEY, locale);
  } catch {
    /* storage unavailable: the locale is resolved again after sign-in */
  }
}
