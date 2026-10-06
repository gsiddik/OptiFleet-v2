import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupI18n } from './support/i18n';
import { changeLocale } from '../../src/i18n/i18n';
import { workflowButtons } from '../../src/hooks/workflowButtons';
import type { AvailableWorkflowTransition } from '../../src/types';

setupI18n();

const byTarget = {
  IN_PROGRESS: { action: 'start', label: 'Start', labelKey: 'common.fields.start', permission: 'work_order.start' },
  CANCELLED: { action: 'cancel', label: 'Cancel', labelKey: 'common.actions.cancelRecord', permission: 'work_order.cancel' },
};
const transition = (to_status: string, action_label: string) => ({ action_code: to_status === 'IN_PROGRESS' ? 'start' : 'cancel', to_status, action_label, requires_approval: false }) as AvailableWorkflowTransition;

test('module default labels follow the UI language; a tenant rename is shown as configured', async () => {
  await changeLocale('id');
  const buttons = workflowButtons([transition('IN_PROGRESS', 'Start'), transition('CANCELLED', 'Batalkan Order Ini')], byTarget, []);
  assert.deepEqual(buttons.map((b) => b.label), ['Mulai', 'Batalkan Order Ini']);
  const fallback = workflowButtons(null, byTarget, [byTarget.CANCELLED]);
  assert.deepEqual(fallback.map((b) => b.label), ['Batalkan']);
  await changeLocale('en');
  assert.deepEqual(workflowButtons([transition('CANCELLED', 'Cancel')], byTarget, []).map((b) => b.label), ['Cancel']);
});
