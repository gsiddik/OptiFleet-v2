import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { SEGMENT_LABELS } from '../../src/navigation/breadcrumbLabels.ts';

test('every static route segment has an explicit breadcrumb label (no runtime humanizing)', () => {
  const app = readFileSync(join(import.meta.dirname, '../../src/App.tsx'), 'utf8');
  const segments = new Set(
    [...app.matchAll(/path="([^"]+)"/g)].flatMap((m) => m[1].split('/')).filter((s) => s && !s.startsWith(':') && s !== '*'),
  );
  assert.ok(segments.size > 50);
  assert.deepEqual([...segments].filter((s) => !(s in SEGMENT_LABELS)), []);
});
