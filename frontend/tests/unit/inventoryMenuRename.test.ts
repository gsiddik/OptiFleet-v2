import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupI18n } from './support/i18n';
import { changeLocale, missingKeys, t } from '../../src/i18n/i18n';
import { NAV_GROUPS, navLabel, searchNav } from '../../src/layouts/tenantNav';
import { segmentKey, segmentLabel } from '../../src/navigation/breadcrumbLabels';

setupI18n();

const item = (to: string) => NAV_GROUPS.flatMap((g) => g.items).find((i) => i.to === to)!;

test('Inventory Return / Transfer are labelled Stock Return / Stock Transfer in both languages; routes unchanged', async () => {
  await changeLocale('en');
  assert.equal(navLabel(item('/app/returns')), 'Stock Return');
  assert.equal(navLabel(item('/app/stock-transfers')), 'Stock Transfer');
  assert.equal(segmentLabel('returns'), 'Stock Return');
  assert.equal(segmentLabel('stock-transfers'), 'Stock Transfer');
  assert.equal(t('inventory.titles.stockReturn'), 'Stock Return');
  assert.ok(searchNav(NAV_GROUPS, 'stock return').flatMap((g) => g.items).some((i) => i.to === '/app/returns'));

  await changeLocale('id');
  assert.equal(navLabel(item('/app/returns')), 'Retur Stok');
  assert.equal(navLabel(item('/app/stock-transfers')), 'Transfer Stok');
  assert.equal(segmentLabel('returns'), 'Retur Stok');
  assert.equal(segmentLabel('stock-transfers'), 'Transfer Stok');
  assert.equal(t('inventory.titles.stockTransfer'), 'Transfer Stok');
  assert.ok(searchNav(NAV_GROUPS, 'retur stok').flatMap((g) => g.items).some((i) => i.to === '/app/returns'));
  // Vehicle Transfer keeps its own label.
  assert.equal(navLabel(item('/app/vehicle-transfers')), 'Transfer');
  assert.equal(segmentKey('returns'), 'breadcrumb.stockReturn');

  await changeLocale('en');
  assert.deepEqual([...missingKeys], []);
});
