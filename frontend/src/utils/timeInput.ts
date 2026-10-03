/** HH:mm, 24-hour (e.g. 07:30, 14:05). */
export const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/;

/** "0730" → "07:30" while typing; digits and one colon only. */
export function autoColon(raw: string): string {
  const cleaned = raw.replace(/[^\d:]/g, '');
  if (/^\d{3,4}$/.test(cleaned)) return `${cleaned.slice(0, 2)}:${cleaned.slice(2)}`;
  return cleaned.slice(0, 5);
}

/** Today in the browser's local date, as YYYY-MM-DD (date input max). */
export function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
