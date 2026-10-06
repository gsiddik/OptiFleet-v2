/**
 * Bundled translation resources (Vite). English is bundled with the app; Indonesian is a separate chunk
 * loaded when first selected. Files are generated — see scripts/i18n/generate.mjs.
 */
import type { LocaleResources } from './i18n';

type Modules = Record<string, unknown>;

function byNamespace(modules: Modules): LocaleResources {
  return Object.fromEntries(Object.entries(modules).map(([path, tree]) => [path.split('/').pop()!.replace(/\.json$/, ''), tree]));
}

export const englishResources: LocaleResources = byNamespace(import.meta.glob('./locales/en/*.json', { eager: true, import: 'default' }));

const indonesianModules = import.meta.glob('./locales/id/*.json', { import: 'default' });

export async function loadIndonesianResources(): Promise<LocaleResources> {
  const entries = await Promise.all(Object.entries(indonesianModules).map(async ([path, load]) => [path, await load()] as const));
  return byNamespace(Object.fromEntries(entries));
}
