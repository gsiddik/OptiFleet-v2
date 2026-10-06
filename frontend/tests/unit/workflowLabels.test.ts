import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ACTION_VERBS, actionVerbLabel } from '../../src/i18n/workflowActionVerbs.ts';
import { AUTOMATED_ACTIONS, automatedActionLabel } from '../../src/i18n/workflowAutomatedActions.ts';
import { isDefaultActionLabel } from '../../src/i18n/workflowActionVerbs.ts';

const ROOT = join(import.meta.dirname, '../../..');

test('action verbs are verbs; statuses keep their own labels', () => {
  assert.equal(actionVerbLabel('APPROVED'), 'Approve');
  assert.equal(actionVerbLabel('CANCELLED'), 'Cancel');
  assert.equal(actionVerbLabel('ON_HOLD'), 'Hold');
  assert.equal(actionVerbLabel('UNKNOWN'), undefined);
});

test('platform default labels (legacy status form or default verb) are not treated as a tenant rename', () => {
  const t = (action_label: string, to_status = 'APPROVED', action_code = 'approved') => ({ action_label, to_status, action_code });
  assert.equal(isDefaultActionLabel(t('Approved')), true, 'legacy status-form label');
  assert.equal(isDefaultActionLabel(t('Approve')), true, 'owner-approved default verb');
  assert.equal(isDefaultActionLabel(t('Qc Pending', 'QC_PENDING', 'qc_pending')), true, 'legacy ucwords label');
  assert.equal(isDefaultActionLabel(t('approved')), true, 'action code');
  assert.equal(isDefaultActionLabel(t('Sign off')), false, 'tenant rename');
});

test('automated action catalog: every backend action has a label', () => {
  const php = readFileSync(join(ROOT, 'backend/app/Domain/Workflow/Services/WorkflowActionCatalog.php'), 'utf8');
  const block = php.slice(php.indexOf('ACTIONS = ['), php.indexOf('];', php.indexOf('ACTIONS = [')));
  const codes = [...block.matchAll(/'([A-Z_]+)'/g)].map((m) => m[1]);
  assert.equal(codes.length, 10);
  assert.deepEqual(codes.filter((c) => !AUTOMATED_ACTIONS[c]), []);
  assert.equal(automatedActionLabel('SEND_NOTIFICATION'), 'Send notification');
  assert.equal(automatedActionLabel('NEW_ONE'), 'NEW_ONE');
});

test('every action verb and automated action key exists in the EN-ID dataset', () => {
  const csv = readFileSync(join(ROOT, 'docs/i18n/12-en-id-translation-dataset-final.csv'), 'utf8');
  const keys = [...Object.values(ACTION_VERBS), ...Object.values(AUTOMATED_ACTIONS)].map((e) => e.key);
  assert.deepEqual(keys.filter((k) => !csv.includes(`\n${k},`)), []);
});
