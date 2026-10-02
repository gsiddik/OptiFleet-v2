import { positionCode, spareCodes, type AxleGroupKey, type Side } from './wheelLayout';
import type { BodyStyle } from './vehicleTypes';

/**
 * Top-view layout engine for the wheel configuration preview (SVG user units), derived only from
 * the form data — no static artwork. The vehicle faces up: FRONT is the top of the drawing.
 * One engine for every vehicle type; only the proportions differ (BODY_PROFILES).
 *
 * Proportion rules (owner feedback):
 * - body width is a fixed multiple of the tire width per type;
 * - body length follows the axles: front overhang + front group + wheelbase + rear group + rear
 *   overhang (overhangs in tire lengths, so the body never looks like a box between the wheels);
 * - three spacing levels: AXLE_PITCH inside every group (small, identical for all types) <
 *   wheelbase between the front and rear group (per type) < Semi Trailer tractor–trailer
 *   separation (the largest);
 * - wheel 1 of each side sits just outside the body edge, further wheels go outwards;
 * - an axle line runs between the centres of its two outermost wheels and is drawn underneath the
 *   body and the wheels, so it never shows beyond the outer tire;
 * - spare tires get their own column outside the body.
 * The body itself is drawn by a per-type renderer (VehicleBodies.tsx) from this geometry.
 */

export const WHEEL = { width: 16, length: 34, gapBetween: 3, gapToBody: 4, radius: 4 } as const;
/** Spacing between axles of the same group — the same for every vehicle type. */
export const AXLE_PITCH = WHEEL.length + 16;
const MARGIN = { top: 44, bottom: 24, side: 34 };
const SPARE_GAP = 46; // space between the outermost wheels and the spare tire column

export interface BodyProfile {
  /** body width in tire widths */
  widthInTires: number;
  /** body length ahead of the first axle / behind the last axle, in tire lengths */
  frontOverhangInTires: number;
  rearOverhangInTires: number;
  /** distance between the last front axle and the first rear axle (> AXLE_PITCH) */
  wheelbase: number;
  /** fixed space above the body for parts sticking out in front (forklift forks) */
  frontExtension?: number;
  /** space above the body as a share of the body length (trailer tow bar: 15%, kept short so it does not dominate the box) */
  frontExtensionRatio?: number;
}

/** Per-type proportions — prototype values reviewed with the owner. */
export const BODY_PROFILES: Record<BodyStyle, BodyProfile> = {
  PASSENGER_CAR: { widthInTires: 6.5, frontOverhangInTires: 1.8, rearOverhangInTires: 1.5, wheelbase: 150 },
  VAN: { widthInTires: 6.75, frontOverhangInTires: 1.6, rearOverhangInTires: 1.9, wheelbase: 170 },
  BUS: { widthInTires: 7.5, frontOverhangInTires: 1.4, rearOverhangInTires: 2.8, wheelbase: 260 },
  FORKLIFT: { widthInTires: 6, frontOverhangInTires: 1, rearOverhangInTires: 1.9, wheelbase: 96, frontExtension: 72 },
  HEAVY_EQUIPMENT: { widthInTires: 8, frontOverhangInTires: 1.7, rearOverhangInTires: 2.2, wheelbase: 190 },
  TRUCK: { widthInTires: 7.25, frontOverhangInTires: 1.5, rearOverhangInTires: 1.9, wheelbase: 220 },
  TRAILER: { widthInTires: 7.25, frontOverhangInTires: 1.3, rearOverhangInTires: 1.6, wheelbase: 230, frontExtensionRatio: 0.15 },
  // Largest separation: the gap between the tractor (front group) and the trailer (rear group).
  SEMI_TRAILER: { widthInTires: 7.25, frontOverhangInTires: 1.2, rearOverhangInTires: 1.6, wheelbase: 300 },
};

export interface PreviewInput {
  /** wheels per side per axle; null = not entered / invalid yet (axle drawn without wheels) */
  front: (number | null)[];
  rear: (number | null)[];
  spareTires: number;
}

export interface WheelRect {
  code: string;
  group: AxleGroupKey;
  axle: number;
  side: Side;
  index: number;
  x: number;
  y: number;
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
  width: number;
  height: number;
  centerX: number;
  /** top of the drawing area reserved for the FRONT marker */
  frontMarkerY: number;
  body: { top: number; bottom: number; left: number; right: number };
  /** space used above body.top by parts that stick out in front (forks, tow bar) */
  frontExtension: number;
  frontOverhang: number;
  rearOverhang: number;
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
  const bodyWidth = profile.widthInTires * WHEEL.width;
  const frontOverhang = profile.frontOverhangInTires * WHEEL.length;
  const rearOverhang = profile.rearOverhangInTires * WHEEL.length;

  const maxPerSide = Math.max(1, ...[...input.front, ...input.rear].map((n) => n ?? 0));
  const reach = WHEEL.gapToBody + maxPerSide * WHEEL.width + (maxPerSide - 1) * WHEEL.gapBetween;
  const spareColumn = input.spareTires > 0 ? SPARE_GAP + WHEEL.length : 0;
  const centerX = MARGIN.side + reach + bodyWidth / 2;
  const width = centerX + bodyWidth / 2 + reach + spareColumn + MARGIN.side;

  // Body length from the axles, so the front extension (tow bar) can be a share of it.
  const span = (n: number) => Math.max(0, n - 1) * AXLE_PITCH;
  const groupsLength = input.front.length && input.rear.length ? span(input.front.length) + profile.wheelbase + span(input.rear.length) : span(input.front.length + input.rear.length);
  const bodyLength = frontOverhang + groupsLength + rearOverhang;
  const frontExtension = Math.round(profile.frontExtensionRatio ? profile.frontExtensionRatio * bodyLength : (profile.frontExtension ?? 0));

  // Axle centre lines, front to back.
  const bodyTop = MARGIN.top + frontExtension;
  const firstAxle = bodyTop + frontOverhang;
  const frontYs = input.front.map((_, i) => firstAxle + i * AXLE_PITCH);
  const frontEnd = frontYs.length ? frontYs[frontYs.length - 1] : firstAxle - AXLE_PITCH;
  const rearStart = frontYs.length ? frontEnd + profile.wheelbase : firstAxle;
  const rearYs = input.rear.map((_, i) => rearStart + i * AXLE_PITCH);
  const bodyBottom = bodyTop + bodyLength;
  const height = bodyBottom + MARGIN.bottom;
  const left = centerX - bodyWidth / 2;
  const right = centerX + bodyWidth / 2;

  const wheels: WheelRect[] = [];
  const axles: AxleLine[] = [];
  const layoutGroup = (group: AxleGroupKey, values: (number | null)[], ys: number[]) =>
    values.forEach((perSide, i) => {
      const y = ys[i];
      const count = perSide ?? 0;
      for (let index = 1; index <= count; index++) {
        const offset = WHEEL.gapToBody + (index - 1) * (WHEEL.width + WHEEL.gapBetween);
        const base = { group, axle: i + 1, index, y: y - WHEEL.length / 2 };
        wheels.push({ ...base, code: positionCode(group, i + 1, 'L', index), side: 'L', x: left - offset - WHEEL.width });
        wheels.push({ ...base, code: positionCode(group, i + 1, 'R', index), side: 'R', x: right + offset });
      }
      // Ends at the centre of the outermost wheel (covered by it); without wheels, at the body.
      const outer = count > 0 ? WHEEL.gapToBody + (count - 1) * (WHEEL.width + WHEEL.gapBetween) + WHEEL.width / 2 : 0;
      axles.push({ label: `${group}${i + 1}`, group, y, x1: left - outer, x2: right + outer, complete: perSide !== null });
    });
  layoutGroup('F', input.front, frontYs);
  layoutGroup('R', input.rear, rearYs);

  // Spare tires: their own column to the right of the vehicle, outside the body and the axles.
  const spareX = right + reach + SPARE_GAP;
  const spares = spareCodes(input.spareTires).map((code, i) => ({ code, x: spareX, y: bodyBottom - rearOverhang - i * (WHEEL.width + 10) - WHEEL.width }));

  return {
    style,
    width,
    height,
    centerX,
    frontMarkerY: 14,
    body: { top: bodyTop, bottom: bodyBottom, left, right },
    frontExtension,
    frontOverhang,
    rearOverhang,
    frontYs,
    rearYs,
    axles,
    wheels,
    spares,
    spareLabel: spares.length ? { x: spareX + WHEEL.length / 2, y: spares[spares.length - 1].y - 8 } : null,
    wheelbase: frontYs.length && rearYs.length ? { y1: frontEnd, y2: rearStart } : null,
  };
}
