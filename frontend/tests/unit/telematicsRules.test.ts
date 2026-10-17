import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  TELEMATICS_STATES,
  ageParts,
  asksForReason,
  canSwitch,
  compareDecimal,
  dispositionKey,
  lowersOdometer,
  newIdempotencyKey,
  qualityTone,
  reviewReasonKey,
  sourceKey,
  stateKeys,
  stateTone,
} from '../../src/pages/tenant/vehicles/telematicsRules.ts';

test('every feed state has a tone, a label key and a help key; an unknown state is never shown raw', () => {
  for (const state of TELEMATICS_STATES) {
    assert.deepEqual(stateKeys(state), { label: `telematics.state.${state}`, help: `telematics.stateHelp.${state}` });
  }
  assert.equal(stateKeys('something_new').label, 'telematics.state.unknown');
  assert.equal(stateTone('ok'), 'good');
  assert.equal(stateTone('error'), 'bad');
  assert.equal(stateTone('not_subscribed'), 'warn');
  assert.equal(stateTone('something_new'), 'neutral');
});

test('the activation switch is offered only for a linked organization on an installation with OptiNexus', () => {
  assert.equal(canSwitch({ enabled: true, linked: true }), true);
  assert.equal(canSwitch({ enabled: true, linked: false }), false);
  assert.equal(canSwitch({ enabled: false, linked: true }), false);
});

test('a device that never reported has no age (not zero)', () => {
  assert.equal(ageParts(null), null);
  assert.equal(ageParts(undefined), null);
  assert.equal(ageParts(-5), null);
  assert.deepEqual(ageParts(0), { unit: 'minutes', value: 0 });
  assert.deepEqual(ageParts(59 * 60 + 59), { unit: 'minutes', value: 59 });
  assert.deepEqual(ageParts(3600), { unit: 'hours', value: 1 });
  assert.deepEqual(ageParts(47 * 3600), { unit: 'hours', value: 47 });
  assert.deepEqual(ageParts(48 * 3600), { unit: 'days', value: 2 });
});

test('decimal strings compare exactly, without floating point', () => {
  assert.equal(compareDecimal('10000.00', '10000'), 0);
  assert.equal(compareDecimal('9999.99', '10000.00'), -1);
  assert.equal(compareDecimal('10000.01', '10000'), 1);
  assert.equal(compareDecimal('99', '100'), -1);
  assert.equal(compareDecimal('0.1', '0.10'), 0);
  assert.equal(compareDecimal('00012.5', '12.50'), 0);
  assert.equal(compareDecimal('12345678901234567.01', '12345678901234567.02'), -1, 'beyond float precision');
  assert.equal(compareDecimal('', '1'), null);
  assert.equal(compareDecimal('abc', '1'), null);
  assert.equal(compareDecimal('-1', '1'), null);
});

test('lowering the odometer is recognised so a reason is asked for before the server has to refuse', () => {
  assert.equal(lowersOdometer('10000.00', '9999.99'), true);
  assert.equal(lowersOdometer('10000.00', '10000'), false);
  assert.equal(lowersOdometer('10000.00', '10000.01'), false);
  assert.equal(lowersOdometer('10000.00', ''), false, 'an empty or invalid entry is not a lowering');
});

test('the server codes that ask for a correction reason are the ones the form reacts to', () => {
  assert.equal(asksForReason('ODOMETER_BELOW_CURRENT'), true);
  assert.equal(asksForReason('ODOMETER_TIME_CONFLICT'), true);
  assert.equal(asksForReason('ODOMETER_FUTURE_TIME'), false);
  assert.equal(asksForReason(undefined), false);
});

test('labels fall back to a neutral key for a value a newer backend adds', () => {
  assert.equal(sourceKey('manual'), 'vehicle.odometer.source.manual');
  assert.equal(sourceKey(null), 'vehicle.odometer.source.unknown');
  assert.equal(sourceKey('future_source'), 'vehicle.odometer.source.other');
  assert.equal(dispositionKey('quarantined'), 'vehicle.odometer.disposition.quarantined');
  assert.equal(dispositionKey('future'), 'vehicle.odometer.disposition.other');
  assert.equal(reviewReasonKey('DEVICE_CHANGED'), 'telematics.review.reason.DEVICE_CHANGED');
  assert.equal(reviewReasonKey('future'), 'telematics.review.reason.unknown');
  assert.equal(qualityTone('SUSPECT'), 'bad');
  assert.equal(qualityTone('LATE'), 'warn');
  assert.equal(qualityTone('OK'), 'good');
  assert.equal(qualityTone(null), 'neutral');
});

test('each submit of the entry form gets its own idempotency key', () => {
  const a = newIdempotencyKey();
  const b = newIdempotencyKey();
  assert.match(a, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/);
  assert.notEqual(a, b);
});
