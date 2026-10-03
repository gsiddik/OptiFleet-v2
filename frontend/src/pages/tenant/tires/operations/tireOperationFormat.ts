/**
 * Selection colours (accessible on white and as tire fill with white text, ≥ 4.5:1):
 * Replacement = brick red, Inspection = sky, Rotation = one colour per pair from the palette.
 */
export const REPLACEMENT_COLOR = '#a8321f';
export const INSPECTION_COLOR = '#0369a1';
export const ROTATION_PALETTE = ['#7c3aed', '#b45309', '#0f766e', '#be185d', '#1d4ed8', '#4d7c0f', '#9a3412', '#334155'];
/** The returning arrow of a rotation pair uses a neutral second colour so both directions are distinguishable. */
export const ROTATION_RETURN_COLOR = '#475569';

/** "12500.75" → "12,500.75"; null → "—". Display only — values are never computed on the client. */
export function formatKm(value: string | null | undefined): string {
  if (value == null || value === '') return '—';
  const [whole, fraction] = String(value).split('.');
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction && Number(fraction) !== 0 ? `.${fraction}` : '');
}
