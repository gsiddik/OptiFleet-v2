import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { initI18n, registerLocaleLoader, type LocaleResources } from '../../../src/i18n/i18n';

const LOCALES_DIR = join(dirname(fileURLToPath(import.meta.url)), '../../../src/i18n/locales');

/** Generated resources for a locale, read from disk (the app bundles them through Vite instead). */
export function localeResources(locale: string): LocaleResources {
  const dir = join(LOCALES_DIR, locale);
  return Object.fromEntries(readdirSync(dir).map((f) => [f.replace(/\.json$/, ''), JSON.parse(readFileSync(join(dir, f), 'utf8'))]));
}

/** Initializes the i18n runtime exactly like the app bootstrap, with on-disk resources. */
export function setupI18n(): void {
  initI18n(localeResources('en'));
  registerLocaleLoader('id', async () => localeResources('id'));
}
