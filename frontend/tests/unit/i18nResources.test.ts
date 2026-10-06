import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
// @ts-expect-error plain ESM script without type declarations
import { load, outputs, selectEntries, validate, normalizePlaceholders, parseCsv } from '../../scripts/i18n/generate.mjs';

type Entry = { key: string; en: string; id: string; duplicateOf?: string | null };
const row = (key: string, en: string, id: string, extra: Record<string, string> = {}) => ({ translation_key: key, source_text_en: en, translated_text_id: id, superseded_by: '', notes: '', ...extra });

test('the approved dataset generates without validation errors', () => {
  const { entries, errors, skipped } = load();
  assert.deepEqual(errors, []);
  assert.ok(entries.length > 5000);
  assert.ok(skipped.mongoDeferred > 0, 'MongoDB-blocked rows are deferred, not generated');
});

test('generated resources are committed and up to date', () => {
  const { entries } = load();
  const stale = [...(outputs(entries) as Map<string, string>)].filter(([path, content]) => !existsSync(path) || readFileSync(path, 'utf8') !== content);
  assert.deepEqual(stale.map(([p]) => p), [], 'run `npm run i18n:generate`');
});

test('validation catches empty texts, parameter and tag mismatches, collisions and bad keys', () => {
  const entries: Entry[] = [
    { key: 'common.a', en: 'A', id: '' },
    { key: 'common.b', en: '{{count}} items', id: '{{jumlah}} item' },
    { key: 'common.c', en: '<link>Open</link>', id: 'Buka' },
    { key: 'common.d', en: 'D', id: 'D' },
    { key: 'common.d.e', en: 'E', id: 'E' },
    { key: 'Bad key', en: 'x', id: 'x' },
  ];
  const errors = validate(entries, []).join('\n');
  for (const expected of ['empty Indonesian: common.a', 'parameter mismatch common.b', 'rich-text tag mismatch common.c', 'collides with a parent key: common.d.e', 'invalid key: Bad key']) assert.match(errors, new RegExp(expected.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
});

test('additions may add plural forms but not redefine a dataset key; duplicates are reported', () => {
  const { entries } = selectEntries([row('common.items', '{{count}} item(s)', '{{count}} item')], [row('common.items', 'x', 'x'), row('common.items_one', '{{count}} item', '{{count}} item'), row('common.items_one', 'y', 'y')]);
  const errors = validate(entries, [row('common.items', 'x', 'x'), row('common.items_one', 'a', 'a'), row('common.items_one', 'b', 'b')]).join('\n');
  assert.match(errors, /additions redefine dataset key common\.items/);
  assert.match(errors, /duplicate key in additions: common\.items_one/);
});

test('superseded and MongoDB-blocked rows are excluded; single-brace placeholders are normalized', () => {
  const { entries, skipped } = selectEntries([row('a.old', 'x', 'x', { superseded_by: 'a.new' }), row('a.blocked', 'x', 'x', { notes: 'BLOCKED_BY_MONGODB_TEST_ENVIRONMENT' }), row('a.kept', '{count} days', '{count} hari')], []);
  assert.deepEqual(entries.map((e: Entry) => e.key), ['a.kept']);
  assert.equal(entries[0].en, '{{count}} days');
  assert.equal(skipped.superseded, 1);
  assert.equal(skipped.mongoDeferred, 1);
  assert.equal(normalizePlaceholders('{{already}} {single}'), '{{already}} {{single}}');
});

test('CSV parser handles quotes, commas and newlines', () => {
  assert.deepEqual(parseCsv('a,b\n"x, ""y""","line1\nline2"\n'), [{ a: 'x, "y"', b: 'line1\nline2' }]);
});
