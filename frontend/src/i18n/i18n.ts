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

/**
 * The shown text of a module-level option / tab / menu entry. Constants keep their English `label` (a stable
 * identity, e.g. for legacy links) and carry a `labelKey`; translating at render time follows the language.
 */
export function labelText(entry: { readonly label: string; readonly labelKey?: string }): string {
  return entry.labelKey ? translated(entry.labelKey, entry.label) : entry.label;
}

/**
 * A module-level option list whose `label` is read in many places: each item's `label` becomes a getter that
 * returns the text in the current language (its English when it has no `labelKey`). `labelEn` keeps the
 * English. The items are new objects; the source list is not changed.
 */
export function withLabels<T extends { readonly label: string; readonly labelKey?: string }>(items: readonly T[]): (T & { readonly labelEn: string })[] {
  return items.map((item) => {
    // Copy the descriptors, not the values: getters on the item (e.g. a translated `help`) keep reading live.
    const copy = Object.defineProperties({}, Object.getOwnPropertyDescriptors(item)) as T & { labelEn: string };
    Object.defineProperty(copy, 'labelEn', { value: item.label, enumerable: false });
    Object.defineProperty(copy, 'label', { get: () => labelText(item), enumerable: true });
    return copy;
  });
}

/**
 * A module-level code → English label map, read as `MAP[code]`: every entry with a key reads in the current
 * language (a getter); entries without a key stay English. Iteration order and the codes are unchanged.
 */
export function translatedRecord<K extends string>(english: Readonly<Record<K, string>>, keys: Readonly<Partial<Record<K, string>>>): Readonly<Record<K, string>> {
  const out = {} as Record<K, string>;
  for (const code of Object.keys(english) as K[]) {
    const key = keys[code];
    if (key) Object.defineProperty(out, code, { get: () => translated(key, english[code]), enumerable: true });
    else out[code] = english[code];
  }
  return out;
}

/** True when the key exists in English (the complete locale). */
export function hasKey(key: string): boolean {
  return i18n.isInitialized && i18n.exists(key, { lng: DEFAULT_LOCALE });
}
