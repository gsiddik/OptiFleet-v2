import { test } from 'node:test';
import assert from 'node:assert/strict';
import { browserLocale, resolveUiLocale } from '../../src/i18n/locale';

test('the user preference wins over the tenant default and the browser', () => {
  assert.equal(resolveUiLocale({ preferred: 'id', tenantDefault: 'en', browser: ['en-US'] }), 'id');
  assert.equal(resolveUiLocale({ preferred: 'en', tenantDefault: 'id', browser: ['id-ID'] }), 'en');
});

test('C: without a preference the tenant default applies', () => {
  assert.equal(resolveUiLocale({ preferred: null, tenantDefault: 'id', browser: ['en-US'] }), 'id');
});

test('D: without a preference or tenant default, the browser, then English', () => {
  assert.equal(resolveUiLocale({ preferred: null, tenantDefault: null, browser: ['id-ID', 'en'] }), 'id');
  assert.equal(resolveUiLocale({ preferred: null, tenantDefault: null, browser: ['fr-FR', 'de'] }), 'en');
  assert.equal(resolveUiLocale({ browser: [] }), 'en');
});

test('unsupported or display-name values are ignored', () => {
  assert.equal(resolveUiLocale({ preferred: 'Bahasa Indonesia', tenantDefault: 'fr', browser: [] }), 'en');
  assert.equal(browserLocale(['ID_id']), 'id');
  assert.equal(browserLocale(['pt-BR']), null);
});
