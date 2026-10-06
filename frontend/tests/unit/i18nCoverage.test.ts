import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { localeResources } from './support/i18n';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '../../src');

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return name === 'locales' ? [] : sourceFiles(path);
    return /\.(ts|tsx)$/.test(name) ? [path] : [];
  });
}

function flatten(tree: unknown, prefix = ''): string[] {
  if (typeof tree !== 'object' || tree === null) return [prefix];
  return Object.entries(tree as Record<string, unknown>).flatMap(([k, v]) => flatten(v, prefix ? `${prefix}.${k}` : k));
}

const keysOf = (locale: string) => new Set(flatten(localeResources(locale)));
/** i18next plural lookups resolve `key` to `key_one` / `key_other`. */
const has = (keys: Set<string>, key: string) => keys.has(key) || keys.has(`${key}_one`) || keys.has(`${key}_other`);

/**
 * Every literal key the code references: t('…'), i18n.t('…'), i18nKey="…", translated('…'), and the
 * `key: '…'` entries of the display registries.
 */
function referencedKeys(): Map<string, string> {
  const found = new Map<string, string>();
  const patterns = [/\bt\(\s*['"`]([a-z][\w]*(?:\.[\w]+)+)['"`]/g, /i18nKey=\{?['"`]([a-z][\w]*(?:\.[\w]+)+)['"`]/g, /\btranslated\(\s*['"`]([a-z][\w]*(?:\.[\w]+)+)['"`]/g];
  // Registries declare their keys as `key: '…'`; elsewhere `key:` is a data path (e.g. analytics columns).
  const registryKey = /\bkey:\s*['"]([a-z][\w]*(?:\.[\w]+)+)['"]/g;
  for (const file of sourceFiles(SRC)) {
    const text = readFileSync(file, 'utf8');
    const relative = file.slice(SRC.length + 1);
    for (const re of relative.startsWith('i18n/') ? [...patterns, registryKey] : patterns) for (const m of text.matchAll(re)) if (!found.has(m[1])) found.set(m[1], relative);
  }
  return found;
}

test('every translation key referenced in the code exists in English and Indonesian', () => {
  const en = keysOf('en');
  const id = keysOf('id');
  const missing = [...referencedKeys()].filter(([key]) => !has(en, key) || !has(id, key)).map(([key, file]) => `${key} (${file})`);
  assert.deepEqual(missing, [], 'add the key to docs/i18n/17-i18n-additions.csv and run npm run i18n:generate');
});

test('English and Indonesian resources have exactly the same keys', () => {
  const en = [...keysOf('en')].sort();
  const id = [...keysOf('id')].sort();
  assert.deepEqual(en.filter((k) => !id.includes(k)), []);
  assert.deepEqual(id.filter((k) => !en.includes(k)), []);
});
