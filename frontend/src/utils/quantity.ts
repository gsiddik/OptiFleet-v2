/**
 * Display a quantity without meaningless trailing decimals: "5.0000" -> "5", "2.5000" -> "2.5".
 * Presentation only — values are still sent to and stored by the backend as decimals, since
 * some Items (litres, kilograms) legitimately use fractions.
 */
export function formatQty(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
}
