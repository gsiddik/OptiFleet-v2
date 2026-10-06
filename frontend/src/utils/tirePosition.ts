import { message } from '../i18n/messages';

/**
 * Tire position codes — one parser/formatter for every screen.
 *
 *   <axle number in group><F|R><L|R><wheel index from the body>   e.g. 1FL1, 2RR2
 *   S<n>                                                          spare tire, e.g. S1
 *
 * Legacy free-text positions from before Wheels Configuration (e.g. FRONT_LEFT, REAR_LEFT) are
 * still shown, humanised, so historical records stay readable.
 */
export type ParsedPosition =
  | { kind: 'AXLE'; code: string; group: 'F' | 'R'; side: 'L' | 'R'; axle: number; index: number }
  | { kind: 'SPARE'; code: string; index: number }
  | { kind: 'LEGACY'; code: string };

const AXLE = /^(\d+)([FR])([LR])(\d+)$/;
const SPARE = /^S(\d+)$/;

export function parsePositionCode(raw: string): ParsedPosition {
  const code = raw.trim().toUpperCase();
  const axle = AXLE.exec(code);
  if (axle) return { kind: 'AXLE', code, axle: Number(axle[1]), group: axle[2] as 'F' | 'R', side: axle[3] as 'L' | 'R', index: Number(axle[4]) };
  const spare = SPARE.exec(code);
  if (spare) return { kind: 'SPARE', code, index: Number(spare[1]) };
  return { kind: 'LEGACY', code: raw.trim() };
}

/** "Front Left, Axle 1, Pos. 1" · "Spare 1" · legacy "FRONT_LEFT" → "Front Left". */
export function describePositionCode(raw: string): string {
  const p = parsePositionCode(raw);
  if (p.kind === 'AXLE')
    return message('common.tire.positionLabel', {
      axleGroup: message(p.group === 'F' ? 'common.fields.front' : 'common.fields.rear'),
      side: message(p.side === 'L' ? 'common.fields.left' : 'common.fields.right'),
      axle: p.axle,
      index: p.index,
    });
  if (p.kind === 'SPARE') return message('common.fields.spareIndex', { index: p.index });
  return p.code
    .toLowerCase()
    .split(/[_\s-]+/)
    .filter(Boolean)
    .map((w) => w[0].toUpperCase() + w.slice(1))
    .join(' ');
}

/** "1FL1 (Front Left, Axle 1, Pos. 1)" — one line, for tables and lists. */
export function formatPositionCode(raw: string | null | undefined): string {
  if (!raw) return '—';
  const p = parsePositionCode(raw);
  return p.kind === 'LEGACY' ? describePositionCode(raw) : `${p.code} (${describePositionCode(raw)})`;
}
