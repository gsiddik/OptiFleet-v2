import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { STATUS_CODES, statusDisplayKey, statusEntry, statusLabel } from '../../src/i18n/statusRegistry.ts';
import { datasetByKey } from './support/dataset.ts';

const ROOT = join(import.meta.dirname, '../../..');

function files(dir: string, ext: RegExp): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? files(path, ext) : ext.test(name) ? [path] : [];
  });
}

const codesIn = (list: string): string[] => [...list.matchAll(/['"]([A-Z][A-Z0-9_]+)['"]/g)].map((m) => m[1]);

/** Canonical status codes declared in backend code, seeders and migrations, and in frontend status lists. */
function canonicalStatusCodes(): Map<string, string> {
  const found = new Map<string, string>();
  const add = (codes: string[], where: string) => codes.forEach((c) => found.has(c) || found.set(c, where));
  for (const f of files(join(ROOT, 'backend/app'), /\.php$/)) {
    const s = readFileSync(f, 'utf8');
    for (const m of s.matchAll(/const [A-Z_]*STATUSES[A-Z_]*\s*=\s*\[([^\]]*)\]/g)) add(codesIn(m[1]), f);
    for (const m of s.matchAll(/const STATUS_[A-Z0-9_]+\s*=\s*'([A-Z][A-Z0-9_]+)'/g)) add([m[1]], f);
  }
  for (const f of files(join(ROOT, 'backend/database/seeders'), /(Workflow|Status).*\.php$/)) {
    add([...readFileSync(f, 'utf8').matchAll(/'code'\s*=>\s*'([A-Z][A-Z0-9_]+)'/g)].map((m) => m[1]), f);
  }
  for (const f of files(join(ROOT, 'backend/database/migrations'), /\.php$/)) {
    for (const m of readFileSync(f, 'utf8').matchAll(/enum\('status',\s*\[([^\]]*)\]/g)) add(codesIn(m[1]), f);
  }
  for (const f of files(join(ROOT, 'frontend/src'), /\.tsx?$/)) {
    for (const m of readFileSync(f, 'utf8').matchAll(/const [A-Z_]*STATUS(?:ES|_OPTIONS)?[A-Z_]*[^=\n]*=\s*\[([\s\S]*?)\]/g)) add(codesIn(m[1]), f);
  }
  return found;
}

test('every canonical status code has a registry entry', () => {
  const codes = canonicalStatusCodes();
  assert.ok(codes.size > 100, `extraction found only ${codes.size} codes — the source scan is broken`);
  const missing = [...codes].filter(([code]) => !statusEntry(code)).map(([code, where]) => `${code} (${where.replace(ROOT, '')})`);
  assert.deepEqual(missing, []);
});

test('every registry key has a final Indonesian translation in the EN-ID dataset', () => {
  const dataset = datasetByKey();
  const keys = [...STATUS_CODES.map((c) => statusDisplayKey(c)!), statusDisplayKey('ISSUED', 'document')!, statusDisplayKey('ISSUED', 'stock')!];
  const problems = keys.filter((k) => !dataset.get(k)?.translated_text_id);
  assert.deepEqual(problems, []);
});

test('domain disambiguates ISSUED (document vs stock)', () => {
  assert.equal(statusDisplayKey('ISSUED', 'document'), 'status.document.issued');
  assert.equal(statusDisplayKey('ISSUED', 'stock'), 'status.stock.issued');
  assert.equal(statusDisplayKey('ISSUED'), 'status.issued');
  assert.equal(statusDisplayKey('APPROVED', 'stock'), 'status.approved');
});

test('labels come from the registry, not from reformatting the code', () => {
  assert.equal(statusLabel('UNDER_REVIEW'), 'Under Review');
  assert.equal(statusLabel('QC_PENDING'), 'QC Pending');
  assert.equal(statusLabel('SCRAPPED'), 'Scrap');
  assert.equal(statusLabel('active'), 'Active');
});

test('unknown or empty codes are shown as-is', () => {
  assert.equal(statusLabel('SOMETHING_NEW'), 'SOMETHING_NEW');
  assert.equal(statusLabel(''), '');
  assert.equal(statusLabel(null), '');
  assert.equal(statusDisplayKey('SOMETHING_NEW'), undefined);
});
