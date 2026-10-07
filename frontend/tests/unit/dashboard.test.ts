import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupI18n } from './support/i18n';
import { changeLocale, t } from '../../src/i18n/i18n';
import { widgetParams } from '../../src/pages/tenant/dashboard/params';
import { codeLabel, col, headerText, widgetKey } from '../../src/pages/tenant/dashboard/labels';

setupI18n();

const filters = { branch_id: 'b1', workshop_id: 'w1', warehouse_id: 'h1', months: 6 };

test('only the filters a widget declares are sent', () => {
  assert.deepEqual(widgetParams(filters, ['branch']), { branch_id: 'b1' });
  assert.deepEqual(widgetParams(filters, ['branch', 'workshop', 'period']), { branch_id: 'b1', workshop_id: 'w1', months: 6 });
  assert.deepEqual(widgetParams({ ...filters, branch_id: '' }, ['branch', 'warehouse'], { status: 'OPEN' }), { status: 'OPEN', warehouse_id: 'h1' });
});

test('column headers are translation keys resolved at render time in both languages', async () => {
  const header = col('vehicle');
  assert.equal(header, 'dashboard.columns.vehicle');
  await changeLocale('en');
  assert.equal(headerText(header), 'Vehicle');
  assert.equal(codeLabel('IMMOBILIZED', 'severity'), 'Immobilized');
  await changeLocale('id');
  assert.equal(headerText(header), 'Kendaraan');
  assert.equal(codeLabel('OUT', 'stock'), 'Stok habis');
  assert.equal(headerText('Plain'), 'Plain');
  await changeLocale('en');
});

test('every widget has a title, help and empty text in English and Indonesian', async () => {
  const ids = ['FL-01', 'FL-02', 'FL-03', 'FL-06', 'MT-01', 'MT-02', 'MT-03', 'WS-01', 'WS-02', 'WS-05', 'WS-06',
    'WH-01', 'WH-02', 'WH-03', 'PR-01', 'PR-02', 'TR-01', 'TR-02', 'TR-03', 'FN-01', 'FN-02', 'FN-03', 'FN-04', 'FN-05', 'FN-06',
    'FL-04', 'FL-05', 'WS-03', 'WS-04', 'WH-04', 'WH-05', 'PR-03', 'PR-04', 'TR-04', 'AL-01',
    'FN-07', 'FN-08', 'WS-07', 'WS-08'];
  for (const locale of ['en', 'id'] as const) {
    await changeLocale(locale);
    for (const id of ids) {
      for (const part of ['title', 'help', 'empty']) {
        const key = `dashboard.widgets.${widgetKey(id)}.${part}`;
        assert.notEqual(t(key), key, `${locale}: ${key}`);
      }
    }
  }
  await changeLocale('en');
});
