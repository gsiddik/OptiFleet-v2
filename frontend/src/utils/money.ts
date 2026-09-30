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
