/**
 * Quantity presentation standard: never shows meaningless decimals ("50.0000" -> "50").
 * Counted items are whole numbers (enforced by the backend QuantityPolicy), so they always
 * display as integers; only products in a measured unit (Liter, Kg…) can show a fraction
 * ("2.5000" -> "2.5").
 */
export function formatQty(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
}
