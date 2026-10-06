/**
 * i18n runtime (EN / ID) on i18next.
 *
 * Resources are generated from the approved dataset (docs/i18n/12 + 17 → `npm run i18n:generate`) into
 * `locales/<locale>/<namespace>.json`; every key is used with its full dotted path, e.g.
 * `t('common.actions.save')`. English is loaded up front and is the fallback for any key missing in
 * another locale; other locales load on demand. A key missing in every locale is recorded in
 * `missingKeys` (and warned about in development) — the coverage test keeps that set empty.
 */
import i18next, { type TOptions } from 'i18next';
import { DEFAULT_LOCALE, SUPPORTED_LOCALES, setAppLocale, type AppLocale } from './locale';

/** One locale's resources: namespace → nested key tree. */
export type LocaleResources = Record<string, unknown>;

export const i18n = i18next.createInstance();

/** Keys requested at runtime that exist in no loaded locale (development / test detection). */
export const missingKeys = new Set<string>();

const loaders = new Map<AppLocale, () => Promise<LocaleResources>>();

/** Initializes the instance with the English resources (synchronously — nothing renders untranslated). */
export function initI18n(english: LocaleResources, options: { warnMissing?: boolean } = {}): void {
  if (i18n.isInitialized) {
    i18n.addResourceBundle(DEFAULT_LOCALE, 'translation', english, true, true);
    return;
  }
  void i18n.init({
    lng: DEFAULT_LOCALE,
    fallbackLng: DEFAULT_LOCALE,
    supportedLngs: [...SUPPORTED_LOCALES],
    resources: { [DEFAULT_LOCALE]: { translation: english } },
    initAsync: false,
    interpolation: { escapeValue: false },
    returnNull: false,
    returnEmptyString: false,
    saveMissing: true,
    missingKeyHandler: (_lngs, _ns, key) => {
      missingKeys.add(key);
      if (options.warnMissing) console.warn(`[i18n] missing translation key: ${key}`);
    },
  });
}

/** Registers how a locale's resources are fetched the first time it is selected. */
export function registerLocaleLoader(locale: AppLocale, loader: () => Promise<LocaleResources>): void {
  loaders.set(locale, loader);
}

/** Switches the active locale (unsupported values fall back to English); loads its resources once. */
export async function changeLocale(locale: string | null | undefined): Promise<AppLocale> {
  const resolved = setAppLocale(locale);
  if (!i18n.hasResourceBundle(resolved, 'translation')) {
    const loader = loaders.get(resolved);
    if (loader) i18n.addResourceBundle(resolved, 'translation', await loader(), true, true);
  }
  await i18n.changeLanguage(resolved);
  if (typeof document !== 'undefined') document.documentElement.lang = resolved;
  return resolved;
}

/** Translates a full dotted key outside React components (registries, helpers). */
export function t(key: string, params?: TOptions): string {
  return i18n.t(key, params) as string;
}

/**
 * The translation of a registry/catalog key in the active locale, or the given English text when the
 * runtime has not been initialized (unit tests of pure helpers) or the key is not generated.
 */
export function translated(key: string, english: string, params?: Readonly<Record<string, string | number>>): string {
  if (hasKey(key)) return t(key, params);
  return params ? english.replace(/\{\{(\w+)\}\}/g, (whole, name: string) => (name in params ? String(params[name]) : whole)) : english;
}

/** True when the key exists in English (the complete locale). */
export function hasKey(key: string): boolean {
  return i18n.isInitialized && i18n.exists(key, { lng: DEFAULT_LOCALE });
}
