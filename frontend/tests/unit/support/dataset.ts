import { readFileSync } from 'node:fs';
import { join } from 'node:path';

export const REPO_ROOT = join(import.meta.dirname, '../../../..');

export /** Minimal RFC 4180 CSV reader (quoted fields, escaped quotes, embedded newlines). */
function readCsv(path: string): Record<string, string>[] {
  const text = readFileSync(path, 'utf8');
  const rows: string[][] = [];
  let row: string[] = [], field = '', quoted = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (quoted) {
      if (ch === '"' && text[i + 1] === '"') { field += '"'; i++; }
      else if (ch === '"') quoted = false;
      else field += ch;
    } else if (ch === '"') quoted = true;
    else if (ch === ',') { row.push(field); field = ''; }
    else if (ch === '\n') { row.push(field.replace(/\r$/, '')); rows.push(row); row = []; field = ''; }
    else field += ch;
  }
  if (field || row.length) { row.push(field); rows.push(row); }
  const [header, ...body] = rows;
  return body.map((r) => Object.fromEntries(header.map((h, i) => [h, r[i] ?? ''])));
}

/** The final EN-ID dataset keyed by translation_key. */
export function datasetByKey(): Map<string, Record<string, string>> {
  return new Map(readCsv(join(REPO_ROOT, 'docs/i18n/12-en-id-translation-dataset-final.csv')).map((r) => [r.translation_key, r]));
}
