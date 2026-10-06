import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupI18n } from './support/i18n';
import { changeLocale, i18n, missingKeys, t } from '../../src/i18n/i18n';
import { appLocale } from '../../src/i18n/locale';
import { statusLabel } from '../../src/i18n/statusRegistry';
import { actionVerbLabel, isDefaultActionLabel } from '../../src/i18n/workflowActionVerbs';
import { automatedActionLabel } from '../../src/i18n/workflowAutomatedActions';
import { message } from '../../src/i18n/messages';

setupI18n();

test('English is the default and Indonesian loads on demand', async () => {
  await changeLocale('en');
  assert.equal(t('common.actions.save'), 'Save');
  assert.equal(statusLabel('APPROVED'), 'Approved');
  await changeLocale('id');
  assert.equal(appLocale(), 'id');
  assert.equal(t('common.actions.save'), 'Simpan');
  assert.equal(statusLabel('APPROVED'), 'Disetujui');
  assert.equal(statusLabel('ON_HOLD'), 'Ditahan');
  assert.equal(actionVerbLabel('APPROVED'), 'Setujui');
  assert.equal(actionVerbLabel('ON_HOLD'), 'Tahan');
  assert.equal(t('common.actions.cancel'), 'Batal', 'dismiss');
  assert.equal(t('common.actions.cancelRecord'), 'Batalkan', 'cancel a record');
  assert.equal(automatedActionLabel('SEND_NOTIFICATION'), t('workflow.automatedAction.sendNotification'));
  assert.equal(statusLabel('SOMETHING_UNKNOWN'), 'SOMETHING_UNKNOWN', 'unknown codes are shown as stored');
  await changeLocale('en');
});

test('stored English workflow labels are still recognized as defaults in Indonesian', async () => {
  await changeLocale('id');
  assert.equal(isDefaultActionLabel({ action_label: 'Approve', action_code: 'approved', to_status: 'APPROVED' }), true);
  assert.equal(isDefaultActionLabel({ action_label: 'Approved', action_code: 'approved', to_status: 'APPROVED' }), true);
  assert.equal(isDefaultActionLabel({ action_label: 'Sign off', action_code: 'approved', to_status: 'APPROVED' }), false);
  await changeLocale('en');
});

test('whole-sentence messages are rendered per locale with their parameters', async () => {
  await changeLocale('id');
  const text = message('workOrder.confirm.partRequestApprove', { lines: '2 × Kampas Rem', woNumber: 'WO/1' });
  assert.match(text, /2 × Kampas Rem/);
  assert.match(text, /WO\/1/);
  assert.notEqual(text, 'Approve 2 × Kampas Rem for WO/1?');
  await changeLocale('en');
  assert.equal(message('workOrder.confirm.partRequestApprove', { lines: 'x', woNumber: 'WO/1' }), 'Approve x for WO/1?');
});

test('a key missing in Indonesian falls back to English; a key missing everywhere is detected', async () => {
  i18n.addResource('en', 'translation', 'testFixture.onlyEnglish', 'Only in English');
  await changeLocale('id');
  assert.equal(t('testFixture.onlyEnglish'), 'Only in English');
  missingKeys.clear();
  t('testFixture.doesNotExist');
  assert.ok(missingKeys.has('testFixture.doesNotExist'));
  assert.ok(!missingKeys.has('testFixture.onlyEnglish'));
  await changeLocale('en');
});

test('an unsupported locale falls back to English without failing', async () => {
  assert.equal(await changeLocale('fr'), 'en');
  assert.equal(await changeLocale(null), 'en');
  assert.equal(t('common.actions.save'), 'Save');
});
