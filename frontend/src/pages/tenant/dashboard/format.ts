import { t } from '../../../i18n/i18n';
import { appLocale, intlTag } from '../../../i18n/locale';
import { formatMoney } from '../../../utils/money';
import { formatNumber } from '../../../utils/number';

/** "Oct 2026" / "Okt 2026" for a YYYY-MM key, in the UI locale. */
export function monthLabel(month: string, style: 'short' | 'long' = 'short'): string {
  const [y, m] = month.split('-').map(Number);
  return new Intl.DateTimeFormat(intlTag(appLocale()), { month: style, year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, 1)));
}

/** Compact axis label for money ("12.5 jt" / "12.5M"); the exact value stays in tooltips and tables. */
export function compactMoney(value: number): string {
  return new Intl.NumberFormat(intlTag(appLocale()), { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

export function money(value: string | number | null | undefined, currency?: string): string {
  return formatMoney(value, currency);
}

export function count(value: number | string | null | undefined): string {
  return formatNumber(value ?? 0);
}

/** "Updated 10:42" from an ISO timestamp, in the browser's clock (the time the data was computed). */
export function updatedAt(iso: string): string {
  const time = new Intl.DateTimeFormat(intlTag(appLocale()), { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
  return t('dashboard.states.updatedAt', { time });
}

export function days(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  return t('dashboard.units.days', { count: value });
}
