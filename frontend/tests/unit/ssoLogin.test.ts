import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setupI18n } from './support/i18n';
import { changeLocale, hasKey, t } from '../../src/i18n/i18n';
import { ssoErrorKey } from '../../src/auth/ssoErrors';
import { otherApps } from '../../src/auth/ssoApps';

setupI18n();

test('every sso_error code the backend can send maps to a message, unknown codes to the generic one', () => {
  const codes = ['access_denied', 'no_membership', 'user_inactive', 'tenant_not_linked', 'tenant_inactive', 'user_not_provisioned', 'email_not_verified', 'account_conflict'];
  for (const code of codes) assert.notEqual(ssoErrorKey(code), 'auth.ssoErrors.failed', code);
  assert.equal(ssoErrorKey('state_invalid'), 'auth.ssoErrors.failed');
  assert.equal(ssoErrorKey('something_new'), 'auth.ssoErrors.failed');
  assert.equal(ssoErrorKey(null), null);
  assert.equal(ssoErrorKey(''), null);
});

test('the app switcher offers other openable apps and leaves out the current one', () => {
  const sso = {
    tenant_id: 't1',
    logout_url: null,
    apps: [
      { code: 'optifleet', name: 'OptiFleet', launch_url: 'https://fleet.example.test/' },
      { code: 'optiradar', name: 'OptiRadar', launch_url: 'https://radar.example.test/app' },
      { code: 'nolink', name: 'No link', launch_url: null },
      { code: 'broken', name: 'Broken', launch_url: 'not a url' },
    ],
  };
  assert.deepEqual(otherApps(sso, 'https://fleet.example.test').map((a) => a.code), ['optiradar']);
  assert.deepEqual(otherApps(null, 'https://fleet.example.test'), []);
});

test('SSO and telematics screens are translated in English and Indonesian', async () => {
  const keys = [
    'auth.actions.signInWithOptinexus', 'auth.actions.backToSignIn', 'auth.help.or', 'auth.help.completingSignIn', 'auth.apps.label',
    'auth.ssoErrors.accessDenied', 'auth.ssoErrors.tenantNotLinked', 'auth.ssoErrors.userNotProvisioned', 'auth.ssoErrors.emailNotVerified',
    'auth.ssoErrors.accountConflict', 'auth.ssoErrors.failed', 'auth.ssoErrors.ticketInvalid',
    'telematics.titles.links', 'telematics.actions.calibrate', 'telematics.status.held', 'telematics.calibrate.title', 'nav.items.telematics',
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
