import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setupI18n } from './support/i18n';
import { changeLocale, hasKey, t } from '../../src/i18n/i18n';
import { ssoErrorKey } from '../../src/auth/ssoErrors';
import { classifyLaunchFailure, isNavigableUrl, tenantHintFrom } from '../../src/api/productRules';

setupI18n();

test('every sso_error code the backend can send maps to a message, unknown codes to the generic one', () => {
  const codes = ['access_denied', 'no_membership', 'user_inactive', 'tenant_not_linked', 'tenant_not_entitled', 'tenant_inactive', 'user_not_provisioned', 'email_not_verified', 'account_conflict'];
  for (const code of codes) assert.notEqual(ssoErrorKey(code), 'auth.ssoErrors.failed', code);
  assert.equal(ssoErrorKey('state_invalid'), 'auth.ssoErrors.failed');
  assert.equal(ssoErrorKey('something_new'), 'auth.ssoErrors.failed');
  assert.equal(ssoErrorKey(null), null);
  assert.equal(ssoErrorKey(''), null);
});

test('a failed switch is classified from the API code, never from the message', () => {
  assert.equal(classifyLaunchFailure({ message: 'x', code: 'SESSION_ENDED_CENTRALLY', status: 401 }), 'session_ended');
  assert.equal(classifyLaunchFailure({ message: 'x', code: 'PRODUCT_NOT_AVAILABLE', status: 403 }), 'not_available');
  assert.equal(classifyLaunchFailure({ message: 'x', code: 'GOVERNANCE_UNAVAILABLE', status: 503 }), 'unavailable');
  assert.equal(classifyLaunchFailure({ message: 'x' }), 'unavailable', 'no answer at all (offline) is unavailable');
  assert.equal(classifyLaunchFailure({ message: 'x', status: 500 }), 'error');
  assert.equal(classifyLaunchFailure({ message: 'access withdrawn', status: 401 }), 'error', 'the text of a message decides nothing');
});

test('the browser only navigates to a plain http(s) link and only accepts a UUID tenant hint', () => {
  assert.equal(isNavigableUrl('https://radar.example.test/app?tenant_hint=1'), true);
  assert.equal(isNavigableUrl('javascript:alert(1)'), false);
  assert.equal(isNavigableUrl('data:text/html,x'), false);
  assert.equal(isNavigableUrl('https://user:pw@radar.example.test/'), false);
  assert.equal(isNavigableUrl(null), false);
  assert.equal(isNavigableUrl('not a url'), false);
  const id = '0b4f1a0e-5c7d-4a5e-9d1b-1f2a3b4c5d6e';
  assert.equal(tenantHintFrom(id.toUpperCase()), id);
  assert.equal(tenantHintFrom('tenant-1'), null);
  assert.equal(tenantHintFrom('<script>'), null);
  assert.equal(tenantHintFrom(null), null);
});

test('SSO and telematics screens are translated in English and Indonesian', async () => {
  const keys = [
    'auth.actions.signInWithOptinexus', 'auth.actions.backToSignIn', 'auth.help.or', 'auth.help.completingSignIn', 'auth.apps.label', 'auth.apps.opening', 'auth.apps.notAvailable', 'auth.apps.unavailable', 'auth.apps.openFailed', 'auth.help.continuingToOptinexus',
    'auth.ssoErrors.accessDenied', 'auth.ssoErrors.tenantNotLinked', 'auth.ssoErrors.userNotProvisioned', 'auth.ssoErrors.emailNotVerified',
    'auth.ssoErrors.accountConflict', 'auth.ssoErrors.failed', 'auth.ssoErrors.ticketInvalid',
    'telematics.titles.links', 'telematics.actions.calibrate', 'telematics.status.held', 'telematics.status.needsCalibration', 'telematics.status.notNeeded', 'telematics.calibrate.title', 'nav.items.telematics',
  ];
  for (const locale of ['en', 'id'] as const) {
    await changeLocale(locale);
    for (const key of keys) assert.ok(hasKey(key), `${locale}: ${key}`);
  }
  await changeLocale('id');
  assert.equal(t('auth.actions.signInWithOptinexus'), 'Masuk dengan OptiNexus');
  assert.equal(t('telematics.calibrate.title', { registration_number: 'B 1234 XY' }), 'Kalibrasi B 1234 XY');
  await changeLocale('en');
});

test('the SSO ticket is read from the URL once and removed from it before it is exchanged', () => {
  const page = readFileSync(join(import.meta.dirname, '../../src/pages/SsoCallbackPage.tsx'), 'utf8');
  assert.ok(page.indexOf('window.history.replaceState') < page.indexOf('completeSsoLogin(ticket)'));
  assert.ok(page.includes('started.current'));
});
