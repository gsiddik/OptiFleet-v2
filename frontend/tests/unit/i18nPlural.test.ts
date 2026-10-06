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
