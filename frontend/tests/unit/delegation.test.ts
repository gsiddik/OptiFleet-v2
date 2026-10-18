import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setupI18n } from './support/i18n';
import { changeLocale, hasKey, t } from '../../src/i18n/i18n';
import { classifyFailure } from '../../src/api/failureKind';

setupI18n();

const SRC = join(import.meta.dirname, '../../src');
const FILES = [
  'pages/tenant/access/CentralUsersPage.tsx',
  'pages/tenant/access/CentralRolesPage.tsx',
  'pages/tenant/access/AccessPages.tsx',
  'components/delegation/DelegatedRoleFormModal.tsx',
  'components/delegation/DelegationPanels.tsx',
  'components/DataScopeModal.tsx',
];
const read = (file: string) => readFileSync(join(SRC, file), 'utf8');

/** The delegation error codes the backend answers with (DelegationErrors::MAP) that the UI treats specially. */
test('a failed request is classified the same way on every central screen', () => {
  assert.equal(classifyFailure({ message: 'x', status: 403, code: 'ACTOR_NOT_TENANT_ADMIN' }), 'forbidden');
  assert.equal(classifyFailure({ message: 'x', status: 403 }), 'forbidden');
  assert.equal(classifyFailure({ message: 'x' }), 'unavailable', 'no answer at all: offline, blocked or timed out');
  assert.equal(classifyFailure({ message: 'x', status: 503, code: 'DELEGATION_UNAVAILABLE', retryable: true }), 'unavailable');
  assert.equal(classifyFailure({ message: 'x', status: 503, code: 'GOVERNANCE_UNAVAILABLE' }), 'unavailable');
  assert.equal(classifyFailure({ message: 'x', status: 502, code: 'DELEGATION_UNEXPECTED' }), 'error');
  assert.equal(classifyFailure({ message: 'x', status: 409, code: 'ROLE_IN_USE' }), 'error');
  assert.equal(classifyFailure({ message: 'x', status: 422, code: 'PERMISSION_NOT_DELEGABLE' }), 'error');
  assert.equal(classifyFailure({ message: 'x', status: 404, code: 'USER_NOT_IN_TENANT' }), 'error');
});

test('every text of the central users and roles screens exists in English and Indonesian and is translated', async () => {
  const keys = new Set<string>(['delegation.users.outcome.created', 'delegation.users.outcome.linked', 'delegation.users.outcome.existing', 'delegation.state.forbidden', 'delegation.state.unavailable', 'delegation.state.error']);
  for (const file of FILES) for (const m of read(file).matchAll(/\bt\(\s*'([^']+)'/g)) keys.add(m[1]);
  assert.ok(keys.size > 30, 'the screens use their texts through t()');

  const english = new Map<string, string>();
  await changeLocale('en');
  for (const key of keys) {
    assert.ok(hasKey(key) || hasKey(`${key}_other`), `en: ${key}`);
    english.set(key, t(key, { name: 'N', id: 'I', count: 2, selected: 1, total: 2, roles: 'R' }));
  }
  await changeLocale('id');
  const untranslated: string[] = [];
  for (const key of keys) {
    assert.ok(hasKey(key) || hasKey(`${key}_other`), `id: ${key}`);
    if (t(key, { name: 'N', id: 'I', count: 2, selected: 1, total: 2, roles: 'R' }) === english.get(key) && !['delegation.users.administrator'].includes(key) && !key.startsWith('access.') && !key.startsWith('common.')) untranslated.push(key);
  }
  await changeLocale('en');
  assert.deepEqual(untranslated, []);
});

test('every refusal of the delegation API has a message in English and Indonesian', async () => {
  const keys = ['actorNotTenantAdmin', 'permissionNotDelegable', 'roleNotAssignable', 'roleNotEditable', 'roleInUse', 'userNotInTenant', 'tenantAdminProtected', 'identityNotLinkable',
    'membershipNotActive', 'selfRevocationDenied', 'applicationNotSubscribed', 'tenantNotActive', 'applicationNotActive', 'misconfigured', 'notFound', 'validation', 'notCentral',
    'unavailable', 'unavailableChange', 'unexpected'].map((k) => `errors.delegation.${k}`);
  for (const locale of ['en', 'id'] as const) {
    await changeLocale(locale);
    for (const key of keys) assert.ok(hasKey(key), `${locale}: ${key}`);
  }
  await changeLocale('en');
});

test('the screens never carry a tenant, an acting user or a password: the server decides who acts and where', () => {
  for (const file of ['api/delegation.ts', ...FILES]) {
    const source = read(file);
    assert.ok(!/acting_user_id|tenant_id|X-Tenant/.test(source), `${file} must not send who acts or which tenant`);
    assert.ok(!/type=["']password["']|initial_password/.test(source), `${file} must not ask for a password`);
  }
});

test('the central pages are only reached for a centrally governed organization and are loaded on demand', () => {
  const pages = read('pages/tenant/access/AccessPages.tsx');
  assert.match(pages, /governance\?\.mode === 'CENTRAL'/);
  assert.match(pages, /lazy\(\(\) => import\('\.\/CentralUsersPage'\)/);
  assert.match(pages, /lazy\(\(\) => import\('\.\/CentralRolesPage'\)/);
});
