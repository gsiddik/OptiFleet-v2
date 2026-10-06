import test from 'node:test';
import assert from 'node:assert/strict';
import { MESSAGE_KEYS, message, messageTemplate } from '../../src/i18n/messages.ts';
import { describePositionCode } from '../../src/utils/tirePosition.ts';
import { describePosition } from '../../src/pages/tenant/tires/wheel-configuration/wheelLayout.ts';
import { datasetByKey } from './support/dataset.ts';

const placeholders = (s: string) => [...s.matchAll(/\{\{(\w+)\}\}/g)].map((m) => m[1]).sort();

test('every message is a dataset key with the same English template and an Indonesian translation with the same parameters', () => {
  const dataset = datasetByKey();
  const problems: string[] = [];
  for (const key of MESSAGE_KEYS) {
    const row = dataset.get(key);
    if (!row) { problems.push(`${key}: missing`); continue; }
    if (row.source_text_en !== messageTemplate(key)) problems.push(`${key}: EN differs ("${row.source_text_en}")`);
    if (!row.translated_text_id) problems.push(`${key}: no Indonesian`);
    else if (placeholders(row.translated_text_id).join() !== placeholders(messageTemplate(key)).join()) problems.push(`${key}: ID parameters differ`);
  }
  assert.deepEqual(problems, []);
});

test('rendered English is identical to the previous concatenated output', () => {
  assert.equal(describePositionCode('1FL2'), 'Front Left, Axle 1, Pos. 2');
  assert.equal(describePositionCode('S1'), 'Spare 1');
  assert.equal(describePosition({ group: 'R', axle: 2, side: 'R', index: 1 }), 'Rear axle 2 · Right · wheel 1 (closest to body)');
  assert.equal(describePosition({ group: 'F', axle: 1, side: 'L', index: 2 }), 'Front axle 1 · Left · wheel 2');
  assert.equal(message('workOrder.confirm.partRequestApprove', { lines: 'Oil Filter × 2', woNumber: 'WO-1' }), 'Approve Oil Filter × 2 for WO-1?');
  assert.equal(message('workOrder.confirm.partRequestCancelNoWorkOrder', { lines: 'Oil Filter × 2' }), 'Cancel Oil Filter × 2 for this Work Order?');
  assert.equal(message('tire.import.rowStatusDuplicate', { errors: 'Serial exists.' }), 'Duplicate — Serial exists.');
});

test('a missing parameter stays visible instead of disappearing', () => {
  assert.equal(message('tire.retread.repairFormTitle'), 'Repair Form — {{serialNumber}}');
});
