import { positionCode, spareCodes, type AxleGroupKey, type Side } from './wheelLayout';
import type { BodyStyle } from './vehicleTypes';

/**
 * Top-view layout engine for the wheel configuration preview (SVG user units), derived only from
 * the form data — no static artwork. The vehicle faces up: FRONT is the top of the drawing.
 *
 * Shared by every vehicle type; only the proportions differ (BODY_PROFILES):
 * - axles inside a group are `axlePitch` apart; the gap between the last front axle and the first
 *   rear axle is the (larger) `wheelbase`, so the two groups read as front and rear;
 * - wheel 1 of each side sits just outside the body edge, further wheels go outwards;
 * - an axle line runs between the centres of its two outermost wheels and is drawn underneath the
 *   body and the wheels, so it never shows beyond the outer tire (same for every type);
 * - spare tires get their own column outside the body.
 * The body itself is drawn by a per-type renderer (VehicleBodies.tsx) from this geometry.
 */

export const WHEEL = { width: 16, length: 34, gapBetween: 3, gapToBody: 4, radius: 4 } as const;
const MARGIN = { top: 44, bottom: 24, side: 34 };
const SPARE_GAP = 46; // space between the outermost wheels and the spare tire column

export interface BodyProfile {
  bodyWidth: number;
  /** body length ahead of the first front axle */
  nose: number;
  /** body length behind the last rear axle */
  tail: number;
  axlePitch: number;
  /** spacing inside the front group when it differs (semi tractor: steer → drive axles) */
  frontAxlePitch?: number;
  /** distance between the last front axle and the first rear axle */
  wheelbase: number;
  /** space above the body for parts that stick out in front (forks, tow bar) */
  frontExtension: number;
}

/** Proportions per body — prototype values for owner review. */
export const BODY_PROFILES: Record<BodyStyle, BodyProfile> = {
  PASSENGER_CAR: { bodyWidth: 104, nose: 64, tail: 52, axlePitch: 50, wheelbase: 150, frontExtension: 0 },
  VAN: { bodyWidth: 108, nose: 56, tail: 70, axlePitch: 50, wheelbase: 190, frontExtension: 0 },
  BUS: { bodyWidth: 120, nose: 52, tail: 96, axlePitch: 50, wheelbase: 280, frontExtension: 0 },
  FORKLIFT: { bodyWidth: 96, nose: 34, tail: 64, axlePitch: 46, wheelbase: 110, frontExtension: 78 },
  HEAVY_EQUIPMENT: { bodyWidth: 128, nose: 58, tail: 74, axlePitch: 52, wheelbase: 180, frontExtension: 0 },
  TRUCK: { bodyWidth: 116, nose: 50, tail: 64, axlePitch: 50, wheelbase: 230, frontExtension: 0 },
  TRAILER: { bodyWidth: 116, nose: 46, tail: 56, axlePitch: 50, wheelbase: 250, frontExtension: 58 },
  SEMI_TRAILER: { bodyWidth: 116, nose: 44, tail: 56, axlePitch: 50, frontAxlePitch: 64, wheelbase: 300, frontExtension: 0 },
};

export interface PreviewInput {
  /** wheels per side per axle; null = not entered / invalid yet (axle drawn without wheels) */
  front: (number | null)[];
  rear: (number | null)[];
  spareTires: number;
}

export interface WheelRect {
  code: string;
  x: number;
  y: number;
  side: Side;
}

export interface AxleLine {
  label: string; // e.g. "F1", "R2"
  group: AxleGroupKey;
  y: number;
  x1: number;
  x2: number;
  complete: boolean;
}

export interface PreviewGeometry {
  style: BodyStyle;
  profile: BodyProfile;
  width: number;
  height: number;
  centerX: number;
  /** top of the drawing area reserved for the FRONT marker */
  frontMarkerY: number;
  body: { top: number; bottom: number; left: number; right: number };
  frontYs: number[];
  rearYs: number[];
  axles: AxleLine[];
  wheels: WheelRect[];
  spares: { code: string; x: number; y: number }[];
  spareLabel: { x: number; y: number } | null;
  wheelbase: { y1: number; y2: number } | null;
}

export function buildPreviewGeometry(input: PreviewInput, style: BodyStyle = 'PASSENGER_CAR'): PreviewGeometry {
  const profile = BODY_PROFILES[style];
  const maxPerSide = Math.max(1, ...[...input.front, ...input.rear].map((n) => n ?? 0));
  const reach = WHEEL.gapToBody + maxPerSide * WHEEL.width + (maxPerSide - 1) * WHEEL.gapBetween;
  const spareColumn = input.spareTires > 0 ? SPARE_GAP + WHEEL.length : 0;
  const centerX = MARGIN.side + reach + profile.bodyWidth / 2;
  const width = centerX + profile.bodyWidth / 2 + reach + spareColumn + MARGIN.side;

  // Axle centre lines, front to back.
  const bodyTop = MARGIN.top + profile.frontExtension;
  const firstAxle = bodyTop + profile.nose;
  const frontPitch = profile.frontAxlePitch ?? profile.axlePitch;
  const frontYs = input.front.map((_, i) => firstAxle + i * frontPitch);
  const frontEnd = frontYs.length ? frontYs[frontYs.length - 1] : firstAxle - frontPitch;
  const rearStart = frontYs.length ? frontEnd + profile.wheelbase : firstAxle;
  const rearYs = input.rear.map((_, i) => rearStart + i * profile.axlePitch);
  const lastY = rearYs.length ? rearYs[rearYs.length - 1] : frontEnd;
  const bodyBottom = Math.max(lastY + profile.tail, bodyTop + profile.nose + profile.tail);
  const height = bodyBottom + MARGIN.bottom;
  const left = centerX - profile.bodyWidth / 2;
  const right = centerX + profile.bodyWidth / 2;

  const wheels: WheelRect[] = [];
  const axles: AxleLine[] = [];
  const layoutGroup = (group: AxleGroupKey, values: (number | null)[], ys: number[]) =>
    values.forEach((perSide, i) => {
      const y = ys[i];
      const count = perSide ?? 0;
      for (let index = 1; index <= count; index++) {
        const offset = WHEEL.gapToBody + (index - 1) * (WHEEL.width + WHEEL.gapBetween);
        wheels.push({ code: positionCode(group, i + 1, 'L', index), side: 'L', x: left - offset - WHEEL.width, y: y - WHEEL.length / 2 });
        wheels.push({ code: positionCode(group, i + 1, 'R', index), side: 'R', x: right + offset, y: y - WHEEL.length / 2 });
      }
      // Ends at the centre of the outermost wheel (covered by it); without wheels, at the body.
      const outer = count > 0 ? WHEEL.gapToBody + (count - 1) * (WHEEL.width + WHEEL.gapBetween) + WHEEL.width / 2 : 0;
      axles.push({ label: `${group}${i + 1}`, group, y, x1: left - outer, x2: right + outer, complete: perSide !== null });
    });
  layoutGroup('F', input.front, frontYs);
  layoutGroup('R', input.rear, rearYs);

  // Spare tires: their own column to the right of the vehicle, outside the body and the axles.
  const spareX = right + reach + SPARE_GAP;
  const spares = spareCodes(input.spareTires).map((code, i) => ({ code, x: spareX, y: bodyBottom - profile.tail - i * (WHEEL.width + 10) - WHEEL.width }));

  return {
    style,
    profile,
    width,
    height,
    centerX,
    frontMarkerY: 14,
    body: { top: bodyTop, bottom: bodyBottom, left, right },
    frontYs,
    rearYs,
    axles,
    wheels,
    spares,
    spareLabel: spares.length ? { x: spareX + WHEEL.length / 2, y: spares[spares.length - 1].y - 8 } : null,
    wheelbase: frontYs.length && rearYs.length ? { y1: frontEnd, y2: rearStart } : null,
  };
}
