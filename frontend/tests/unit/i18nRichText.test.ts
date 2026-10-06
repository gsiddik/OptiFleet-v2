import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { I18nextProvider, Trans } from 'react-i18next';
import { setupI18n } from './support/i18n';
import { changeLocale, i18n } from '../../src/i18n/i18n';

setupI18n();

const notice = () =>
  renderToStaticMarkup(
    createElement(I18nextProvider, { i18n },
      createElement(Trans, {
        i18nKey: 'tire.help.missingTireDataNotice',
        values: { position: 'FL' },
        components: { position: createElement('b'), linkTo: createElement('a', { href: '/app/vehicles/1?tab=wheels' }) },
      }),
    ),
  );

test('rich text keeps one sentence with its inline components in both languages', async () => {
  await changeLocale('en');
  assert.equal(notice(), '<b>FL</b> has no tire data yet. Complete it in <a href="/app/vehicles/1?tab=wheels">Vehicle Details → Wheels Configuration</a> before continuing.');
  await changeLocale('id');
  assert.equal(notice(), '<b>FL</b> belum memiliki data ban. Lengkapi di <a href="/app/vehicles/1?tab=wheels">Detail Kendaraan → Konfigurasi Roda</a> sebelum melanjutkan.');
  await changeLocale('en');
});
