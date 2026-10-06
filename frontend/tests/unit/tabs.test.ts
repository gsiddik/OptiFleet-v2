import test from 'node:test';
import assert from 'node:assert/strict';
import { resolveTabId, tabLabel, type TabDef } from '../../src/utils/tabs.ts';

type Id = 'overview' | 'module-entitlements' | 'contract' | 'issuance-return' | 'wheels';
const TABS: readonly TabDef<Id>[] = [
  { id: 'overview', label: 'Overview' },
  { id: 'module-entitlements', label: 'Module Entitlements' },
  { id: 'contract', label: 'Contract' },
  { id: 'issuance-return', label: 'Issuance & Return' },
  { id: 'wheels', label: 'Wheels Configuration' },
];

test('stable id resolves to itself', () => {
  assert.equal(resolveTabId(TABS, 'contract', 'overview'), 'contract');
  assert.equal(resolveTabId(TABS, 'wheels', 'overview'), 'wheels');
});

test('legacy English label links still resolve (backward compatibility)', () => {
  assert.equal(resolveTabId(TABS, 'Contract', 'overview'), 'contract');
  assert.equal(resolveTabId(TABS, 'Module Entitlements', 'overview'), 'module-entitlements');
  assert.equal(resolveTabId(TABS, 'Issuance & Return', 'overview'), 'issuance-return');
  assert.equal(resolveTabId(TABS, 'Wheels Configuration', 'overview'), 'wheels');
});

test('aliases resolve', () => {
  assert.equal(resolveTabId(TABS, 'contracts', 'overview', { contracts: 'contract' }), 'contract');
});

test('missing, empty or unknown values fall back', () => {
  assert.equal(resolveTabId(TABS, null, 'overview'), 'overview');
  assert.equal(resolveTabId(TABS, '', 'overview'), 'overview');
  assert.equal(resolveTabId(TABS, '%%%', 'overview'), 'overview');
  assert.equal(resolveTabId(TABS, 'Kontrak', 'overview'), 'overview');
});

test('label is display-only and looked up by id', () => {
  assert.equal(tabLabel(TABS, 'issuance-return'), 'Issuance & Return');
});
