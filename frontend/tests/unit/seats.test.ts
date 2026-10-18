import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { setupI18n } from './support/i18n';
import { changeLocale } from '../../src/i18n/i18n';
import type { DelegationSeats } from '../../src/api/delegation';
import { invitationRefusalMessage } from '../../src/components/delegation/invitationPresentation';
import { SEAT_REFUSALS, seatRefusalMessage, seatsView } from '../../src/components/delegation/seatsPresentation';

setupI18n();

const SRC = join(import.meta.dirname, '../../src');

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

function seats(overrides: Partial<DelegationSeats> = {}): DelegationSeats {
  return {
    state: 'LIMITED', controlled: true, limit: 5, unlimited: false, used: 2, reserved: 1, available: 2, over_limit: false, over_by: 0, can_add: true, blocked_reason: null, ...overrides,
  };
}

test('the seats are shown exactly as OptiNexus counted them, in both languages', async () => {
  await inBothLanguages((locale) => {
    const view = seatsView(seats());
    assert.ok(view);
    assert.equal(view.tone, 'info');
    assert.equal(view.text, locale === 'en'
      ? 'User seats: 2 used, 1 reserved by open invitations, 2 available of a limit of 5.'
      : 'Kursi pengguna: 2 terpakai, 1 dicadangkan undangan terbuka, 2 tersedia dari batas 5.');
  });
});

test('no seat left is a warning, a limit below usage is "over" and never a negative number', async () => {
  await inBothLanguages((locale) => {
    const full = seatsView(seats({ used: 3, reserved: 2, available: 0, can_add: false, blocked_reason: 'SEAT_LIMIT_REACHED' }));
    assert.ok(full);
    assert.equal(full.tone, 'warning');
    assert.match(full.text, locale === 'en' ? /No seat is left/ : /Tidak ada kursi tersisa/);

    const over = seatsView(seats({ limit: 2, used: 3, reserved: 1, available: 0, over_limit: true, over_by: 2, can_add: false, blocked_reason: 'SEAT_OVER_LIMIT' }));
    assert.ok(over);
    assert.equal(over.tone, 'warning');
    assert.match(over.title ?? '', locale === 'en' ? /Over the seat limit by 2/ : /Melebihi batas kursi sebanyak 2/);
    assert.match(over.text, locale === 'en' ? /No access is removed automatically/ : /Tidak ada akses yang dicabut otomatis/);
    assert.ok(!`${over.title}${over.text}`.includes('-'), 'no negative figure');
  });
});

test('a limit that could not be decided blocks additions and is never shown as unlimited', async () => {
  await inBothLanguages((locale) => {
    for (const state of ['ENTITLEMENT_MISSING', 'ENTITLEMENT_INVALID', 'ENTITLEMENT_UNAVAILABLE'] as const) {
      const view = seatsView(seats({ state, limit: null, available: null, can_add: false, blocked_reason: `SEAT_${state}` }));
      assert.ok(view);
      assert.equal(view.tone, 'warning', state);
      assert.ok(!/no limit|tanpa batas/i.test(view.text), `${locale}: ${state} must not read as unlimited`);
      assert.match(view.text, locale === 'en' ? /nobody new can be added/ : /orang baru tidak dapat ditambahkan/);
    }
  });
});

test('unlimited is said only for an explicit unlimited entitlement; a product without a limit says no limit is applied', async () => {
  await inBothLanguages((locale) => {
    const unlimited = seatsView(seats({ state: 'UNLIMITED', unlimited: true, limit: null, available: null }));
    const none = seatsView(seats({ state: 'NOT_CONTROLLED', controlled: false, limit: null, available: null }));
    assert.match(unlimited?.text ?? '', locale === 'en' ? /\(no limit\)/ : /\(tanpa batas\)/);
    assert.match(none?.text ?? '', locale === 'en' ? /does not apply a seat limit to this product \(2 users with access\)/ : /tidak menerapkan batas kursi untuk produk ini \(2 pengguna dengan akses\)/);
  });
});

test('an older OptiNexus without figures shows nothing instead of a guess', () => {
  assert.equal(seatsView(null), null);
  assert.equal(seatsView(undefined), null);
});

test('a seat refusal is a plain notice with the server sentence, OptiNexus\' figures and the wait', async () => {
  await inBothLanguages((locale) => {
    const reached = invitationRefusalMessage({ message: 'server text', status: 409, code: 'SEAT_LIMIT_REACHED', details: { limit: 5, used: 3, reserved: 2, available: 0, recovery: 'FREE_SEAT_OR_RAISE_LIMIT' } });
    assert.equal(reached, `server text ${locale === 'en' ? '3 used and 2 reserved of a limit of 5.' : '3 terpakai dan 2 dicadangkan dari batas 5.'}`);

    const transient = invitationRefusalMessage({ message: 'server text', status: 503, code: 'SEAT_CONTENTION', retry_after_seconds: 4 });
    assert.equal(transient, `server text ${locale === 'en' ? 'Try again in 4 s.' : 'Coba lagi dalam 4 dtk.'}`);

    for (const code of SEAT_REFUSALS) {
      assert.equal(typeof invitationRefusalMessage({ message: 'x', status: 409, code }), 'string', `${code} is a notice, not an error panel`);
    }
    assert.equal(seatRefusalMessage({ message: 'only text' }), 'only text', 'a figure that was not sent is not invented');
  });
});

test('the screens show the seats of the answer and OptiFleet keeps no count of its own', () => {
  const users = readFileSync(join(SRC, 'pages/tenant/access/CentralUsersPage.tsx'), 'utf8');
  const invitations = readFileSync(join(SRC, 'components/delegation/InvitationsPanel.tsx'), 'utf8');
  assert.match(users, /<SeatsNotice seats=\{users\.data\.seats\} \/>/);
  assert.match(invitations, /<SeatsNotice seats=\{list\.data\.seats\} \/>/);
  const presentation = readFileSync(join(SRC, 'components/delegation/seatsPresentation.ts'), 'utf8');
  assert.ok(!/\.length|reduce\(|filter\(/.test(presentation.replace(/\/\*[\s\S]*?\*\//g, '')), 'no counting in the presentation');
});
