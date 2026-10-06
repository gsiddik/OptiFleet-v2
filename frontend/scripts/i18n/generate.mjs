#!/usr/bin/env node
/**
 * Translation resource generator (EN / ID).
 *
 * Source of truth: docs/i18n/12-en-id-translation-dataset-final.csv (the approved dataset) plus
 * docs/i18n/17-i18n-additions.csv (strings added during the rollout through the controlled new-string
 * flow, and plural forms). Generated output — never edit by hand, run `npm run i18n:generate`:
 *
 *   frontend/src/i18n/locales/{en,id}/<namespace>.json   nested by key path, namespace = first key segment
 *   backend/lang/{en,id}/catalog.php                     flat `full.key => text` map read by Messages
 *
 * `--check` validates and fails if the generated files are missing or out of date (used by the unit
 * tests / CI). Validation: duplicate keys, empty texts, invalid key or namespace, `{{param}}` mismatch
 * between English and Indonesian, keys that would collide in the nested layout, unsupported plural
 * suffixes.
 */
import { readFileSync, writeFileSync, mkdirSync, existsSync, readdirSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const DATASET = join(ROOT, 'docs/i18n/12-en-id-translation-dataset-final.csv');
const ADDITIONS = join(ROOT, 'docs/i18n/17-i18n-additions.csv');
const FRONTEND_OUT = join(ROOT, 'frontend/src/i18n/locales');
const BACKEND_OUT = join(ROOT, 'backend/lang');
export const LOCALES = ['en', 'id'];
const KEY_RE = /^[a-z][A-Za-z0-9]*(\.[A-Za-z0-9_]+)+$/;
const PLURAL_SUFFIXES = ['_zero', '_one', '_other'];

/** RFC 4180 CSV parser (quoted fields, embedded commas, quotes and newlines). */
export function parseCsv(text) {
  const rows = [];
  let row = [], field = '', quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
      else if (c === '"') quoted = false;
      else field += c;
    } else if (c === '"') quoted = true;
    else if (c === ',') { row.push(field); field = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(field); rows.push(row); row = []; field = '';
    } else field += c;
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row); }
  const [header, ...body] = rows.filter((r) => r.length > 1 || r[0] !== '');
  return body.map((r) => Object.fromEntries(header.map((h, i) => [h, r[i] ?? ''])));
}

/** `{name}` (single braces, used by a few legacy rows) becomes `{{name}}`. */
export function normalizePlaceholders(text) {
  return text.replace(/(?<!\{)\{([A-Za-z_][\w.]*)\}(?!\})/g, '{{$1}}');
}

export const params = (text) => [...new Set([...text.matchAll(/\{\{\s*([\w.]+)\s*\}\}/g)].map((m) => m[1]))].sort();
const tags = (text) => [...new Set([...text.matchAll(/<([a-zA-Z][\w]*)>/g)].map((m) => m[1]))].sort();

/** Rows that are part of the generated resources, and why the others are left out. */
export function selectEntries(datasetRows, additionRows) {
  const entries = new Map();
  const skipped = { superseded: 0, mongoDeferred: 0, invalidKey: [] };
  for (const r of datasetRows) {
    if (r.superseded_by) { skipped.superseded++; continue; }
    if ((r.notes || '').includes('BLOCKED_BY_MONGODB_TEST_ENVIRONMENT')) { skipped.mongoDeferred++; continue; }
    if (!KEY_RE.test(r.translation_key)) { skipped.invalidKey.push(r.translation_key); continue; }
    entries.set(r.translation_key, { key: r.translation_key, en: normalizePlaceholders(r.source_text_en), id: normalizePlaceholders(r.translated_text_id), origin: 'dataset' });
  }
  for (const r of additionRows) {
    entries.set(r.translation_key, { key: r.translation_key, en: normalizePlaceholders(r.source_text_en), id: normalizePlaceholders(r.translated_text_id), origin: 'additions', duplicateOf: entries.has(r.translation_key) && !/_(zero|one|other)$/.test(r.translation_key) ? r.translation_key : null });
  }
  return { entries: [...entries.values()], skipped };
}

export function validate(entries, additionRows) {
  const errors = [];
  const seen = new Set();
  for (const r of additionRows) {
    if (seen.has(r.translation_key)) errors.push(`duplicate key in additions: ${r.translation_key}`);
    seen.add(r.translation_key);
  }
  const keys = new Set(entries.map((e) => e.key));
  for (const e of entries) {
    if (e.duplicateOf) errors.push(`additions redefine dataset key ${e.key} (use the dataset, or a plural _one/_other key)`);
    if (!KEY_RE.test(e.key)) errors.push(`invalid key: ${e.key}`);
    const last = e.key.split('.').pop();
    if (last.includes('_') && /_(zero|one|other|few|many|two)$/.test(last) && !PLURAL_SUFFIXES.some((s) => last.endsWith(s))) errors.push(`unsupported plural suffix: ${e.key}`);
    if (!e.en.trim()) errors.push(`empty English: ${e.key}`);
    if (!e.id.trim()) errors.push(`empty Indonesian: ${e.key}`);
    if (params(e.en).join() !== params(e.id).join()) errors.push(`parameter mismatch ${e.key}: en [${params(e.en)}] id [${params(e.id)}]`);
    if (tags(e.en).join() !== tags(e.id).join()) errors.push(`rich-text tag mismatch ${e.key}: en [${tags(e.en)}] id [${tags(e.id)}]`);
    const parts = e.key.split('.');
    for (let i = 1; i < parts.length; i++) if (keys.has(parts.slice(0, i).join('.'))) errors.push(`key collides with a parent key: ${e.key}`);
  }
  return errors;
}

/** Nested JSON per namespace (first key segment) and a flat backend catalog, per locale. */
/**
 * Rich-text tags for <Trans>: a tag named like an HTML void element (<link>) would be parsed as self-closing
 * and lose its text, so the frontend resources rename it (<link>…</link> → <linkTo>…</linkTo>). The dataset
 * and the backend catalog keep the original text.
 */
const VOID_TAGS = { link: 'linkTo', input: 'inputTo', img: 'imgTo', br: 'brTo', hr: 'hrTo', meta: 'metaTo' };
export const reactTags = (text) => text.replace(/<(\/?)(link|input|img|br|hr|meta)>/g, (m, slash, tag) => `<${slash}${VOID_TAGS[tag]}>`);

export function build(entries) {
  const frontend = Object.fromEntries(LOCALES.map((l) => [l, {}]));
  const backend = Object.fromEntries(LOCALES.map((l) => [l, {}]));
  for (const e of [...entries].sort((a, b) => a.key.localeCompare(b.key))) {
    const [ns, ...path] = e.key.split('.');
    for (const l of LOCALES) {
      let node = (frontend[l][ns] ??= {});
      path.slice(0, -1).forEach((p) => { node = node[p] ??= {}; });
      node[path.at(-1)] = reactTags(e[l]);
      backend[l][e.key] = e[l];
    }
  }
  return { frontend, backend };
}

const phpString = (s) => "'" + s.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
function phpCatalog(map, locale) {
  const lines = Object.entries(map).map(([k, v]) => `    ${phpString(k)} => ${phpString(v)},`);
  return `<?php\n\n// GENERATED by frontend/scripts/i18n/generate.mjs from docs/i18n/12 + 17 — do not edit. Locale: ${locale}.\n\nreturn [\n${lines.join('\n')}\n];\n`;
}

export function outputs(entries) {
  const { frontend, backend } = build(entries);
  const files = new Map();
  for (const l of LOCALES) {
    for (const [ns, tree] of Object.entries(frontend[l])) files.set(join(FRONTEND_OUT, l, `${ns}.json`), JSON.stringify(tree, null, 2) + '\n');
    files.set(join(BACKEND_OUT, l, 'catalog.php'), phpCatalog(backend[l], l));
  }
  return files;
}

export function load() {
  const datasetRows = parseCsv(readFileSync(DATASET, 'utf8'));
  const additionRows = existsSync(ADDITIONS) ? parseCsv(readFileSync(ADDITIONS, 'utf8')) : [];
  const { entries, skipped } = selectEntries(datasetRows, additionRows);
  return { datasetRows, additionRows, entries, skipped, errors: validate(entries, additionRows) };
}

function main() {
  const check = process.argv.includes('--check');
  const { entries, skipped, errors } = load();
  if (errors.length) {
    console.error(`i18n: ${errors.length} validation error(s):\n  ` + errors.join('\n  '));
    process.exit(1);
  }
  const files = outputs(entries);
  const stale = [];
  for (const [path, content] of files) {
    if (!existsSync(path) || readFileSync(path, 'utf8') !== content) {
      if (check) stale.push(path.slice(ROOT.length + 1));
      else { mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, content); }
    }
  }
  for (const l of LOCALES) {
    const dir = join(FRONTEND_OUT, l);
    if (!existsSync(dir)) continue;
    for (const f of readdirSync(dir)) {
      const p = join(dir, f);
      if (files.has(p)) continue;
      if (check) stale.push(`${p.slice(ROOT.length + 1)} (orphan)`);
      else rmSync(p);
    }
  }
  if (check && stale.length) {
    console.error('i18n: generated resources are out of date — run `npm run i18n:generate`:\n  ' + stale.join('\n  '));
    process.exit(1);
  }
  console.log(`i18n: ${entries.length} keys, ${new Set(entries.map((e) => e.key.split('.')[0])).size} namespaces; skipped ${skipped.superseded} superseded, ${skipped.mongoDeferred} MongoDB-deferred, ${skipped.invalidKey.length} non-key rows (${skipped.invalidKey.join('; ') || '—'})${check ? '; up to date' : '; written'}.`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
