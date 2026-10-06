import test from 'node:test';
import assert from 'node:assert/strict';
import { appLocale, monthNames, setAppLocale } from '../../src/i18n/locale.ts';
import { formatDate, formatDateTime, formatTime, formatTimestampDate } from '../../src/utils/date.ts';
import { formatNumber } from '../../src/utils/number.ts';
import { formatQty } from '../../src/utils/quantity.ts';

test('default locale is the system fallback en', () => {
  assert.equal(appLocale(), 'en');
  assert.equal(setAppLocale('fr'), 'en');
  assert.equal(setAppLocale('id'), 'id');
  setAppLocale('en');
});

test('month names come from Intl for the locale', () => {
  assert.deepEqual(monthNames('short', 'en').slice(8, 10), ['Sep', 'Oct']);
  assert.deepEqual(monthNames('short', 'id').slice(4, 10), ['Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt']);
  assert.equal(monthNames('long', 'en')[0], 'January');
  assert.equal(monthNames('long', 'id')[9], 'Oktober');
});

test('date-only values keep the calendar date in both locales (output unchanged for en)', () => {
  assert.equal(formatDate('2026-10-31'), '31 Oct 2026');
  assert.equal(formatDate('2026-10-31', 'id'), '31 Okt 2026');
  assert.equal(formatDate('2026-10-31T23:30:00Z'), '31 Oct 2026');
  assert.equal(formatDate(null), '—');
  assert.equal(formatDate('not a date'), 'not a date');
});

test('timestamps format in the viewer time zone with explicit month names', () => {
  const iso = new Date(2026, 9, 6, 14, 5).toISOString();
  assert.equal(formatDateTime(iso), '06 Oct 2026, 14:05');
  assert.equal(formatDateTime(iso, 'id'), '06 Okt 2026, 14:05');
  assert.equal(formatTimestampDate(iso), '06 Oct 2026');
  assert.equal(formatTime(iso), '14:05');
  assert.equal(formatDateTime(undefined), '—');
});

test('numbers use the locale grouping; values are not altered', () => {
  assert.equal(formatNumber(1234.56), '1,234.56');
  assert.equal(formatNumber('1234.56', 'id'), '1.234,56');
  assert.equal(formatNumber(125000), '125,000');
  assert.equal(formatNumber(null), '—');
  assert.equal(formatQty('2.5000'), '2.5');
  assert.equal(formatQty('1500.0000', 'id'), '1.500');
});
