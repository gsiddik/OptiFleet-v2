import { appLocale, monthNames, type AppLocale } from '../i18n/locale';

const pad = (n: number) => String(n).padStart(2, '0');

/**
 * "2026-10-31" (or an ISO timestamp's date part) → "31 Oct 2026" (`id`: "31 Okt 2026"). Date-only values
 * are read as calendar dates, never shifted by the browser's time zone.
 */
export function formatDate(value: string | null | undefined, locale: AppLocale = appLocale()): string {
  if (!value) return '—';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!m) return value;
  return `${m[3]} ${monthNames('short', locale)[Number(m[2]) - 1]} ${m[1]}`;
}

/** ISO timestamp → "31 Oct 2026, 14:05" in the viewer's local time. */
export function formatDateTime(value: string | null | undefined, locale: AppLocale = appLocale()): string {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return `${pad(d.getDate())} ${monthNames('short', locale)[d.getMonth()]} ${d.getFullYear()}, ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** ISO timestamp → its calendar date in the viewer's local time, "31 Oct 2026". */
export function formatTimestampDate(value: string | null | undefined, locale: AppLocale = appLocale()): string {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return `${pad(d.getDate())} ${monthNames('short', locale)[d.getMonth()]} ${d.getFullYear()}`;
}

/** ISO timestamp → "14:05" (24-hour) in the viewer's local time. */
export function formatTime(value: string | null | undefined): string {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
