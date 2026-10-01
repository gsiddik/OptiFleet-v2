import { positionCode, spareCodes, type AxleGroupKey, type Side } from './wheelLayout';

/**
 * Top-view geometry for the wheel configuration preview (SVG user units), derived only from the
 * form data — no static artwork. The vehicle faces up: FRONT is the top of the drawing.
 *
 * Vertical rhythm: axles inside a group are AXLE_PITCH apart; the gap between the last front axle
 * and the first rear axle is the (larger) wheelbase, so the two groups read as front and rear.
 * Horizontally, wheel 1 of each side sits just outside the body and further wheels go outwards.
 * An axle line runs between the centres of its two outermost wheels and is drawn underneath the
 * body and the wheels, so it never shows beyond the outer tire.
 */

export const WHEEL = { width: 16, length: 34, gapBetween: 3, gapToBody: 4, radius: 4 } as const;
const BODY_WIDTH = 104;
const AXLE_PITCH = WHEEL.length + 16;
const WHEELBASE = 150;
const NOSE = 64; // body length ahead of the first axle
const TAIL = 52; // body length behind the last axle
const MARGIN = { top: 44, bottom: 24, side: 34 };
const SPARE_GAP = 46; // space between the outermost wheels and the spare tire column

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
  width: number;
  height: number;
  centerX: number;
  body: { top: number; bottom: number; left: number; right: number };
  windshield: { top: number; bottom: number };
  axles: AxleLine[];
  wheels: WheelRect[];
  spares: { code: string; x: number; y: number }[];
  spareLabel: { x: number; y: number } | null;
  wheelbase: { y1: number; y2: number } | null;
}

export function buildPreviewGeometry(input: PreviewInput): PreviewGeometry {
  const maxPerSide = Math.max(1, ...[...input.front, ...input.rear].map((n) => n ?? 0));
  const reach = WHEEL.gapToBody + maxPerSide * WHEEL.width + (maxPerSide - 1) * WHEEL.gapBetween;
  const spareColumn = input.spareTires > 0 ? SPARE_GAP + WHEEL.length : 0;
  const centerX = MARGIN.side + reach + BODY_WIDTH / 2;
  const width = centerX + BODY_WIDTH / 2 + reach + spareColumn + MARGIN.side;

  // Axle centre lines, front to back.
  const frontYs = input.front.map((_, i) => MARGIN.top + NOSE + i * AXLE_PITCH);
  const frontEnd = frontYs.length ? frontYs[frontYs.length - 1] : MARGIN.top + NOSE - AXLE_PITCH;
  const rearStart = frontYs.length ? frontEnd + WHEELBASE : MARGIN.top + NOSE;
  const rearYs = input.rear.map((_, i) => rearStart + i * AXLE_PITCH);
  const lastY = rearYs.length ? rearYs[rearYs.length - 1] : frontEnd;
  const bodyTop = MARGIN.top;
  const bodyBottom = Math.max(lastY + TAIL, bodyTop + NOSE + TAIL);
  const height = bodyBottom + MARGIN.bottom;
  const left = centerX - BODY_WIDTH / 2;
  const right = centerX + BODY_WIDTH / 2;

  // Windshield just behind the front axle group (or near the nose without one).
  const windshieldTop = frontYs.length ? frontEnd + WHEEL.length / 2 + 14 : bodyTop + 26;

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
  const spares = spareCodes(input.spareTires).map((code, i) => ({ code, x: spareX, y: bodyBottom - TAIL - i * (WHEEL.width + 10) - WHEEL.width }));

  return {
    width,
    height,
    centerX,
    body: { top: bodyTop, bottom: bodyBottom, left, right },
    windshield: { top: windshieldTop, bottom: windshieldTop + 22 },
    axles,
    wheels,
    spares,
    spareLabel: spares.length ? { x: spareX + WHEEL.length / 2, y: spares[spares.length - 1].y - 8 } : null,
    wheelbase: frontYs.length && rearYs.length ? { y1: frontEnd, y2: rearStart } : null,
  };
}
