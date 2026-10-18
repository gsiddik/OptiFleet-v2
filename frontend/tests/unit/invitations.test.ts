import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setupI18n } from './support/i18n';
import { changeLocale, hasKey, t } from '../../src/i18n/i18n';
import { formatDateTime } from '../../src/utils/date';
import type { DelegatedInvitation } from '../../src/api/delegation';
import {
  cooldownDeadline,
  defaultInvitationLocale,
  INVITATION_REFUSALS,
  INVITATION_FILTER_OPTIONS,
  invitationActions,
  invitationDeliveryView,
  invitationRefusalMessage,
  invitationStatusView,
  secondsLeft,
} from '../../src/components/delegation/invitationPresentation';

setupI18n();

const SRC = join(import.meta.dirname, '../../src');
const FILES = [
  'api/delegation.ts',
  'pages/tenant/access/CentralUsersPage.tsx',
  'components/delegation/InvitationsPanel.tsx',
  'components/delegation/invitationPresentation.ts',
  'components/delegation/DelegationPanels.tsx',
];
const read = (file: string) => readFileSync(join(SRC, file), 'utf8');

/** Runs a check in both languages. */
async function inBothLanguages(check: (locale: 'en' | 'id') => void | Promise<void>) {
  try {
    for (const locale of ['en', 'id'] as const) {
      await changeLocale(locale);
      await check(locale);
    }
  } finally {
    await changeLocale('en');
  }
}

function invitation(overrides: Partial<DelegatedInvitation> = {}): DelegatedInvitation {
  return {
    id: '7d2c1a52-0a4e-4d51-9d2e-0b8f3c1f6a10',
    email: 'new.person@example.test',
    name: 'New Person',
    status: 'PENDING',
    locale: 'en',
    role_ids: [],
    roles: [],
    invited_by: { id: 'a', name: 'Admin' },
    created_at: '2026-10-18T08:00:00Z',
    expires_at: '2026-10-21T08:00:00Z',
    last_sent_at: '2026-10-18T08:00:00Z',
    send_count: 1,
    delivery: { status: 'SENT', attempted_at: '2026-10-18T08:01:00Z', error_code: null },
    accepted_at: null,
    revoked_at: null,
    can_resend: true,
    can_revoke: true,
    ...overrides,
  };
}

test('every status chip says what it is, with the line that explains the link, in both languages', async () => {
  const labels = new Map<string, Set<string>>();
  await inBothLanguages((locale) => {
    const expires = formatDateTime('2026-10-21T08:00:00Z');
    const pending = invitationStatusView(invitation({ status: 'PENDING' }));
    const expired = invitationStatusView(invitation({ status: 'EXPIRED' }));
    const accepted = invitationStatusView(invitation({ status: 'ACCEPTED', accepted_at: '2026-10-19T10:00:00Z' }));
    const revoked = invitationStatusView(invitation({ status: 'REVOKED', revoked_at: '2026-10-19T11:00:00Z' }));

    assert.deepEqual([pending.tone, expired.tone, accepted.tone, revoked.tone], ['info', 'warning', 'success', 'neutral']);
    assert.ok(pending.hint.includes(expires), `${locale}: the pending line names the expiry`);
    assert.ok(expired.hint.includes(expires), `${locale}: the expired line names the expiry`);
    assert.ok(accepted.hint.includes(formatDateTime('2026-10-19T10:00:00Z')), `${locale}: the accepted line names the day`);
    assert.ok(revoked.hint.includes(formatDateTime('2026-10-19T11:00:00Z')), `${locale}: the revoked line names the day`);
    // The cases the person must be able to read: expired (what to do), revoked (the link is dead), used (accepted).
    const word = locale === 'en' ? { resend: /resend/i, dead: /no longer works/i, used: /link was used/i } : { resend: /kirim ulang/i, dead: /tidak berlaku/i, used: /sudah digunakan/i };
    assert.match(expired.hint, word.resend);
    assert.match(revoked.hint, word.dead);
    assert.match(accepted.hint, word.used);

    for (const view of [pending, expired, accepted, revoked]) {
      assert.ok(view.label.length > 0 && !view.label.includes('delegation.'), `${locale}: label is translated`);
      labels.set(view.label, (labels.get(view.label) ?? new Set()).add(locale));
    }
  });
  assert.equal(labels.size, 8, 'four statuses in two languages: every label is its own text');
});

test('an unknown status is shown as reported instead of breaking the row', () => {
  const view = invitationStatusView(invitation({ status: 'SUSPENDED' as never }));
  assert.equal(view.label, 'SUSPENDED');
  assert.equal(view.tone, 'neutral');
  assert.equal(view.hint, '');
});

test('delivery shows queued, sent and failed (with the recovery hint and the code) while the link can still be used', async () => {
  await inBothLanguages((locale) => {
    const queued = invitationDeliveryView(invitation({ delivery: { status: 'QUEUED', attempted_at: null, error_code: null } }));
    const sent = invitationDeliveryView(invitation({ delivery: { status: 'SENT', attempted_at: '2026-10-18T08:01:00Z', error_code: null } }));
    const failed = invitationDeliveryView(invitation({ delivery: { status: 'FAILED', attempted_at: '2026-10-18T08:01:00Z', error_code: 'MAIL_TRANSPORT_ERROR' } }));

    assert.ok(queued && sent && failed);
    assert.deepEqual([queued.tone, sent.tone, failed.tone], ['neutral', 'success', 'danger']);
    assert.notEqual(queued.label, sent.label);
    assert.notEqual(sent.label, failed.label);
    assert.ok(sent.hint.includes(formatDateTime('2026-10-18T08:01:00Z')), `${locale}: says when it was sent`);
    assert.match(failed.hint, locale === 'en' ? /Resend/ : /Kirim ulang/, `${locale}: the failed hint says how to recover`);
    assert.match(failed.hint, locale === 'en' ? /support/ : /dukungan/);
    assert.ok(failed.code?.includes('MAIL_TRANSPORT_ERROR'), `${locale}: the code is there for support`);
    assert.equal(queued.code, null);
    assert.equal(sent.code, null);

    // An expired invitation whose mail failed still needs the hint; once used or revoked the mail no longer matters.
    assert.ok(invitationDeliveryView(invitation({ status: 'EXPIRED', delivery: { status: 'FAILED', attempted_at: null, error_code: null } })));
    assert.equal(invitationDeliveryView(invitation({ status: 'ACCEPTED' })), null);
    assert.equal(invitationDeliveryView(invitation({ status: 'REVOKED' })), null);
    assert.equal(invitationDeliveryView(invitation({ delivery: { status: null, attempted_at: null, error_code: null } })), null);
  });
});

test('Resend and Revoke need what OptiNexus allows for the invitation AND the permission, never a role name', () => {
  const allowed = (p: string) => ['user.create', 'user.update'].includes(p);
  const asked: string[] = [];
  const spy = (p: string) => {
    asked.push(p);
    return allowed(p);
  };

  assert.deepEqual(invitationActions(invitation(), spy), { resend: true, revoke: true });
  assert.deepEqual(asked.sort(), ['user.create', 'user.update']);
  assert.deepEqual(invitationActions(invitation({ can_resend: false }), allowed), { resend: false, revoke: true }, 'resend limit reached');
  assert.deepEqual(invitationActions(invitation({ can_resend: false, can_revoke: false }), allowed), { resend: false, revoke: false }, 'accepted or revoked');
  assert.deepEqual(invitationActions(invitation(), (p) => p === 'user.create'), { resend: true, revoke: false });
  assert.deepEqual(invitationActions(invitation(), (p) => p === 'user.view'), { resend: false, revoke: false }, 'a viewer sees the list only');
});

test('refusals of the invitation API are explained, including the cooldown, in both languages', async () => {
  await inBothLanguages((locale) => {
    const base = { message: 'server text', status: 409 };

    const cooldown = invitationRefusalMessage({ ...base, status: 429, code: 'INVITATION_RESEND_COOLDOWN', retry_after_seconds: 42, retryable: true });
    assert.ok(cooldown?.includes('42'), `${locale}: the wait is named`);
    assert.notEqual(cooldown, 'server text');
    assert.equal(invitationRefusalMessage({ ...base, status: 429, code: 'INVITATION_RESEND_COOLDOWN' }), 'server text', 'no wait reported: the server sentence');
    assert.ok(invitationRefusalMessage({ ...base, status: 429, code: 'INVITATION_RATE_LIMITED', retry_after_seconds: 120 })?.includes('120'));

    const accepted = invitationRefusalMessage({ ...base, code: 'INVITATION_NOT_PENDING', details: { status: 'ACCEPTED' } });
    const revoked = invitationRefusalMessage({ ...base, code: 'INVITATION_NOT_PENDING', details: { status: 'REVOKED' } });
    assert.match(accepted ?? '', locale === 'en' ? /already accepted/ : /sudah diterima/);
    assert.match(revoked ?? '', locale === 'en' ? /was revoked/ : /sudah dicabut/);
    assert.equal(invitationRefusalMessage({ ...base, code: 'INVITATION_NOT_PENDING', details: { status: 'EXPIRED' } }), 'server text');

    for (const code of ['INVITATION_PENDING', 'ALREADY_MEMBER', 'ROLE_NOT_ASSIGNABLE', 'INVITATION_NOT_FOUND', 'INVITATION_RESEND_LIMIT', 'DIRECT_USER_CREATION_DISABLED']) {
      assert.equal(invitationRefusalMessage({ ...base, code }), 'server text', `${code} shows the translated sentence of the server`);
    }
  });
  assert.equal(invitationRefusalMessage({ message: 'x', status: 503, code: 'DELEGATION_UNAVAILABLE' }), null, 'an outage keeps the usual panel');
  assert.equal(invitationRefusalMessage({ message: 'x', status: 403, code: 'ACTOR_NOT_TENANT_ADMIN' }), null);
  assert.equal(invitationRefusalMessage({ message: 'x' }), null);
  assert.deepEqual([...INVITATION_REFUSALS].sort(), ['ALREADY_MEMBER', 'DIRECT_USER_CREATION_DISABLED', 'INVITATION_NOT_FOUND', 'INVITATION_NOT_PENDING', 'INVITATION_PENDING', 'INVITATION_RATE_LIMITED', 'INVITATION_RESEND_COOLDOWN', 'INVITATION_RESEND_LIMIT', 'ROLE_NOT_ASSIGNABLE']);
});

test('after a cooldown refusal Resend stays off for the wait OptiNexus asked for, and only then', () => {
  const now = 1_000_000;
  const deadline = cooldownDeadline({ message: 'x', status: 429, code: 'INVITATION_RESEND_COOLDOWN', retry_after_seconds: 30 }, now);
  assert.equal(deadline, now + 30_000);
  assert.equal(secondsLeft(deadline ?? undefined, now), 30);
  assert.equal(secondsLeft(deadline ?? undefined, now + 29_500), 1);
  assert.equal(secondsLeft(deadline ?? undefined, now + 30_000), 0);
  assert.equal(secondsLeft(undefined, now), 0);
  assert.equal(cooldownDeadline({ message: 'x', status: 429, code: 'INVITATION_RESEND_COOLDOWN' }, now), null, 'no wait given: nothing is blocked locally');
  assert.equal(cooldownDeadline({ message: 'x', status: 429, code: 'INVITATION_RATE_LIMITED', retry_after_seconds: 60 }, now), null, 'only the resend cooldown blocks a row');
});

test('the language of a new invitation starts as the language of the screen and is always en or id', () => {
  assert.equal(defaultInvitationLocale('id'), 'id');
  assert.equal(defaultInvitationLocale('en'), 'en');
  assert.equal(defaultInvitationLocale('fr'), 'en');
});

test('every text of the invitation screens exists in English and Indonesian and is translated', async () => {
  const keys = new Set<string>();
  for (const file of FILES) for (const m of read(file).matchAll(/\bt\(\s*'(delegation\.[^']+|errors\.delegation\.[^']+)'/g)) keys.add(m[1]);
  // Keys built from a value: every status, delivery state and hint.
  for (const s of INVITATION_FILTER_OPTIONS) keys.add(`delegation.invitations.status.${s.toLowerCase()}`).add(`delegation.invitations.hint.${s.toLowerCase()}`);
  for (const d of ['queued', 'sent', 'failed']) keys.add(`delegation.invitations.delivery.${d}`).add(`delegation.invitations.delivery.${d}Hint`);
  for (const k of ['invitationPending', 'alreadyMember', 'invitationNotFound', 'invitationNotPending', 'invitationResendCooldown', 'invitationResendLimit', 'invitationRateLimited', 'directCreationDisabled']) keys.add(`errors.delegation.${k}`);
  assert.ok(keys.size > 45, `the screens use their texts through t() (${keys.size})`);

  const params = { name: 'N', email: 'e@example.test', date: '18 Oct 2026', code: 'C', seconds: 5, roles: 'R', count: 2, selected: 1, total: 2, id: 'I' };
  const english = new Map<string, string>();
  await changeLocale('en');
  for (const key of keys) {
    assert.ok(hasKey(key), `en: ${key}`);
    english.set(key, t(key, params));
  }
  await changeLocale('id');
  const same: string[] = [];
  for (const key of keys) {
    assert.ok(hasKey(key), `id: ${key}`);
    const text = t(key, params);
    assert.ok(!/\{\{/.test(text), `id: ${key} has an unfilled placeholder`);
    if (text === english.get(key) && key !== 'delegation.users.administrator') same.push(key); // "Administrator" is the same word in both
  }
  await changeLocale('en');
  assert.deepEqual(same, [], 'a text that is identical in both languages is not translated');
});

test('the users page invites instead of adding, follows the invitations in a tab and uses the central permission check', () => {
  const page = read('pages/tenant/access/CentralUsersPage.tsx');
  assert.match(page, /delegationApi\.invite\(/);
  assert.ok(!/delegationApi\.addUser\(/.test(page), 'the screen no longer adds a user directly');
  assert.ok(!/delegation\.users\.addUser/.test(page), 'no "Add user" action is left');
  assert.match(page, /t\('delegation\.invitations\.inviteUser'\)/);
  assert.match(page, /<InvitationsPanel /);
  assert.match(page, /hasPermission\('user\.create'\)/);
  assert.match(page, /defaultInvitationLocale\(appLocale\(\)\)/, 'the language of the invitation defaults to the UI language');

  const panel = read('components/delegation/InvitationsPanel.tsx');
  assert.match(panel, /invitationActions\(i, hasPermission\)/);
  for (const file of FILES) assert.ok(!/\brole\s*===|\broleName\b|\.role\s*===/.test(read(file)), `${file}: authorization goes through hasPermission, never a role name`);
});

test('the invitation screens never carry a tenant, an acting user, a password or a link', () => {
  for (const file of FILES) {
    const source = read(file);
    assert.ok(!/acting_user_id|tenant_id|X-Tenant/.test(source), `${file} must not send who acts or which tenant`);
    assert.ok(!/type=["']password["']|initial_password|\.token|invitation_url|accept_url/.test(source), `${file} must not handle a password or a link`);
  }
  const api = read('api/delegation.ts');
  assert.match(api, /\/app\/delegation\/invitations`?/);
  assert.ok(!/token/i.test(api.slice(api.indexOf('export interface DelegatedInvitation'), api.indexOf('export const delegationApi'))), 'the invitation type has no token field');
});
