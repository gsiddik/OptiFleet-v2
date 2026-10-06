import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupI18n } from './support/i18n';
import { changeLocale, t } from '../../src/i18n/i18n';

setupI18n();

test('plural keys pick the English form by count (0 / 1 / 2+); Indonesian has one form', async () => {
  await changeLocale('en');
  assert.deepEqual([0, 1, 2, 15].map((count) => t('common.fields.permissionCount', { count })), ['0 permissions', '1 permission', '2 permissions', '15 permissions']);
  await changeLocale('id');
  assert.deepEqual([0, 1, 2].map((count) => t('common.fields.permissionCount', { count })), ['0 izin', '1 izin', '2 izin']);
  await changeLocale('en');
});

test('dataset plural rows (I18N_PLURALIZATION_ROLLOUT_ITEM) resolve one / other by count', async () => {
  await changeLocale('en');
  assert.deepEqual([0, 1, 2].map((count) => t('configuration.help.stepsCountStepS', { count })), ['0 steps.', '1 step.', '2 steps.']);
  assert.equal(t('tire.help.unansweredCountQuestionSStillUnanswered', { count: 1 }), '1 question still unanswered.');
  assert.equal(t('procurement.help.leadDaysDaySAfterPo', { count: 14 }), '14 days after PO');
  assert.equal(t('inventory.actions.createSellableCountSaleSDraft', { count: 1 }), 'Create 1 Sale (Draft)');
  await changeLocale('id');
  assert.deepEqual([1, 3].map((count) => t('configuration.help.stepsCountStepS', { count })), ['1 tahap.', '3 tahap.']);
  assert.equal(t('inventory.actions.createSellableCountSaleSDraft', { count: 2 }), 'Buat 2 Penjualan (Draf)');
  await changeLocale('en');
});
