/**
 * Wheel configuration calculations — pure functions with no UI dependency, so the same rules can
 * be re-implemented server-side when the configuration is persisted.
 *
 * A configuration is two axle groups (front, rear); each axle has a number of wheels per side
 * (the same on the left and the right), plus spare tires carried outside the axles.
 */

export interface AxleGroups {
  /** wheels per side of each front axle, front-most first */
  front: number[];
  /** wheels per side of each rear axle, front-most first */
  rear: number[];
}

/**
 * Prototype input limits (with the reason for each — to be confirmed by the owner):
 * - wheels per side 1–4: the Config Code writes one digit per axle, so 9 is the hard ceiling;
 *   real axles carry 1 (single) or 2 (dual) per side, 4 leaves headroom while the preview stays
 *   legible. 0 is not allowed — an axle without wheels is not an axle.
 * - axles per group 0–6: 0 allows a configuration without a front or a rear group (edge case,
 *   see configCode); 6 covers multi-axle heavy vehicles and keeps the preview readable.
 * - spare tires 0–4.
 * At least one axle in total is required.
 */
export const LIMITS = {
  axlesPerGroup: { min: 0, max: 6 },
  wheelsPerSide: { min: 1, max: 4 },
  spareTires: { min: 0, max: 4 },
} as const;

export type Range = { min: number; max: number };

/** Parses a mandatory whole-number text field; returns the number or an error message. */
export function parseCount(raw: string, range: Range): { value: number | null; error: string | null } {
  const text = raw.trim();
  if (text === '') return { value: null, error: 'Required.' };
  if (!/^\d+$/.test(text)) return { value: null, error: 'Enter a whole number.' };
  const value = Number(text);
  if (value < range.min || value > range.max) return { value: null, error: `Enter a whole number from ${range.min} to ${range.max}.` };
  return { value, error: null };
}

/** Total Axles = Number of Front Axles + Number of Rear Axles. */
export function totalAxles(frontAxles: number, rearAxles: number): number {
  return frontAxles + rearAxles;
}

/** Σ(front wheels/side × 2) + Σ(rear wheels/side × 2) + spare tires — per axle, so axles may differ. */
export function totalWheels(groups: AxleGroups, spareTires: number): number {
  const sum = (axles: number[]) => axles.reduce((total, perSide) => total + perSide * 2, 0);
  return sum(groups.front) + sum(groups.rear) + spareTires;
}

/**
 * Config Code: one digit per front axle (its wheels per side), ".", one digit per rear axle.
 * 2 front axles of 2 + 3 rear axles of 2 → "22.222"; front 1,2 + rear 2,2,1 → "12.221".
 * An empty group is written as a single "0" ("0.22", "22.0"): wheels per side is never 0, so a
 * "0" can only mean "no axle in this group" and the code always has the same "front.rear" shape.
 */
export function configCode(groups: AxleGroups): string {
  const digits = (axles: number[]) => (axles.length === 0 ? '0' : axles.join(''));
  return `${digits(groups.front)}.${digits(groups.rear)}`;
}

export type AxleGroupKey = 'F' | 'R';
export type Side = 'L' | 'R';

export interface WheelPosition {
  /** e.g. "1FL1" = front axle 1, left side, wheel 1 (closest to the body) */
  code: string;
  group: AxleGroupKey;
  /** 1-based axle number within its group (front-most first) */
  axle: number;
  side: Side;
  /** 1 = closest to the body, increasing outwards */
  index: number;
}

/** Position code: <axle number in group><F|R><L|R><wheel index from the body>, e.g. 1FL1, 2RR2. */
export function positionCode(group: AxleGroupKey, axle: number, side: Side, index: number): string {
  return `${axle}${group}${side}${index}`;
}

/** Every axle wheel position, axle by axle (front group first), left then right, inner to outer. */
export function wheelPositions(groups: AxleGroups): WheelPosition[] {
  const positions: WheelPosition[] = [];
  const add = (group: AxleGroupKey, axles: number[]) =>
    axles.forEach((perSide, i) => {
      for (const side of ['L', 'R'] as const) {
        for (let index = 1; index <= perSide; index++) {
          positions.push({ code: positionCode(group, i + 1, side, index), group, axle: i + 1, side, index });
        }
      }
    });
  add('F', groups.front);
  add('R', groups.rear);
  return positions;
}

/** Spare tire identifiers S1…Sn (not axle positions). */
export function spareCodes(spareTires: number): string[] {
  return Array.from({ length: spareTires }, (_, i) => `S${i + 1}`);
}
