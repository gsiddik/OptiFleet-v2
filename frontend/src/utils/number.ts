import { appLocale, intlTag, type AppLocale } from '../i18n/locale';

/**
 * Plain number for display (odometer, counts, readings) with the app locale's grouping:
 * en "12,345.5", id "12.345,5". Presentation only — never parse the result back.
 * Money keeps its own decimal-safe formatting in utils/money.
 */
export function formatNumber(value: number | string | null | undefined, locale: AppLocale = appLocale()): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'number' ? value : Number(value);
  if (Number.isNaN(n)) return String(value);
  return new Intl.NumberFormat(intlTag(locale), { maximumFractionDigits: 3 }).format(n);
}
