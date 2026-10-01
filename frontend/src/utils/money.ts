/**
 * Money presentation standard: always exactly two decimals with thousands separators,
 * rounded half-up (the backend's Money rounding policy). Works on the decimal string the API
 * returns — no floating-point arithmetic — so "100.005" becomes "100.01", not "100.00".
 */
export function formatMoney(value: string | number | null | undefined, currency?: string | null): string {
  if (value === null || value === undefined || value === '') return '—';
  const raw = String(value).trim();
  const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(typeof value === 'number' ? value.toFixed(10) : raw);
  if (!match) return raw;
  const [, sign, intRaw, fracRaw = ''] = match;
  let digits = (intRaw || '0') + (fracRaw + '000').slice(0, 3);
  // Round half-up on the third decimal, in string arithmetic.
  const roundUp = Number(digits[digits.length - 1]) >= 5;
  digits = digits.slice(0, -1);
  if (roundUp) {
    const chars = digits.split('');
    let i = chars.length - 1;
    while (i >= 0) {
      if (chars[i] === '9') {
        chars[i] = '0';
        i -= 1;
      } else {
        chars[i] = String(Number(chars[i]) + 1);
        break;
      }
    }
    digits = (i < 0 ? '1' : '') + chars.join('');
  }
  const intPart = digits.slice(0, -2).replace(/^0+(?=\d)/, '') || '0';
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const isZero = /^[0,]*$/.test(grouped) && /^0*$/.test(digits.slice(-2));
  const amount = `${sign && !isZero ? '-' : ''}${grouped}.${digits.slice(-2)}`;
  return currency ? `${currency} ${amount}` : amount;
}

/**
 * A stored decimal (e.g. "150000.0000") as an editable form value with at most two decimals
 * ("150000.00"), without thousands separators and without floating-point arithmetic. Only
 * trailing zero decimals are dropped — a value with significant 3rd/4th decimals is kept
 * unchanged rather than silently altered.
 */
export function toMoneyInput(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '';
  const raw = String(value).trim();
  const match = /^(-?\d+)(?:\.(\d*))?$/.exec(raw);
  if (!match) return raw;
  const [, int, frac = ''] = match;
  if (frac.length <= 2) return frac ? `${int}.${frac}` : int;
  return /^0*$/.test(frac.slice(2)) ? `${int}.${frac.slice(0, 2)}` : raw;
}

/** Exact sum of decimal money strings (up to 4 decimals, the storage scale) — no floating point. */
export function sumMoney(...values: (string | number | null | undefined)[]): string {
  const SCALE = 4;
  let total = 0n;
  for (const v of values) {
    if (v === null || v === undefined || v === '') continue;
    const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(String(v).trim());
    if (!m) continue;
    const units = BigInt(m[2] + (m[3] ?? '').padEnd(SCALE, '0').slice(0, SCALE));
    total += m[1] ? -units : units;
  }
  const negative = total < 0n;
  const digits = (negative ? -total : total).toString().padStart(SCALE + 1, '0');
  return `${negative ? '-' : ''}${digits.slice(0, -SCALE)}.${digits.slice(-SCALE)}`;
}
