import type { ReactNode } from 'react';
import type { BodyStyle } from './vehicleTypes';
import type { PreviewGeometry } from './wheelPreviewGeometry';

/**
 * Body renderers — one silhouette per vehicle type, drawn from the shared layout geometry
 * (PROTOTYPE designs for owner review). Each draws only the body: axle lines, wheels, spare tires
 * and labels come from WheelConfigurationPreview, so wheel/axle rules are identical for all types.
 * Every body is drawn inside g.body (top = front of the vehicle) plus g.frontExtension above it
 * for parts that stick out in front. Silhouettes only need to tell the types apart — the axle and
 * wheel configuration stays the main subject, so details are kept to a minimum.
 */

const BODY_COLORS = {
  body: '#e5e7eb',
  bodyStroke: '#6b7280',
  glass: '#bfdbfe',
  detail: '#9ca3af',
  dark: '#4b5563',
  accent: '#fde68a',
  accentStroke: '#b45309',
};

type BodyProps = { g: PreviewGeometry };

function roundedFrontPath(left: number, right: number, top: number, bottom: number, nose: number, rear = 10): string {
  const cx = (left + right) / 2;
  return [
    `M ${left} ${top + nose}`,
    `Q ${left} ${top} ${cx} ${top}`,
    `Q ${right} ${top} ${right} ${top + nose}`,
    `L ${right} ${bottom - rear}`,
    `Q ${right} ${bottom} ${right - rear} ${bottom}`,
    `L ${left + rear} ${bottom}`,
    `Q ${left} ${bottom} ${left} ${bottom - rear}`,
    'Z',
  ].join(' ');
}

/** Windshield: trapezoid, wider at the back (drawn just behind y). */
function Windshield({ left, right, y, depth = 22, inset = 12 }: { left: number; right: number; y: number; depth?: number; inset?: number }) {
  return <path d={`M ${left + inset + 6} ${y} L ${right - inset - 6} ${y} L ${right - inset} ${y + depth} L ${left + inset} ${y + depth} Z`} fill={BODY_COLORS.glass} opacity={0.9} />;
}

/** Last front axle (or a fallback) — bodies place their cab/windshield relative to the front group. */
function frontEnd(g: PreviewGeometry): number {
  return g.frontYs.length ? g.frontYs[g.frontYs.length - 1] : g.body.top + g.frontOverhang;
}

export function PassengerCarBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const windshieldTop = g.frontYs.length ? frontEnd(g) + 31 : top + 26;
  return (
    <g>
      <path d={roundedFrontPath(left, right, top, bottom, 26)} fill={BODY_COLORS.body} stroke={BODY_COLORS.bodyStroke} strokeWidth={1.5} />
      <Windshield left={left} right={right} y={windshieldTop} />
      <rect x={left + 12} y={bottom - 30} width={right - left - 24} height={12} rx={3} fill={BODY_COLORS.glass} opacity={0.6} />
    </g>
  );
}

/** Long rectangular body, flat/rounded glazed front, side window strips, roof AC unit. */
export function BusBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const w = right - left;
  // Roof air-conditioning unit just behind the front axles (clear of the wheelbase label).
  const acTop = Math.min(frontEnd(g) + 46, bottom - 140);
  return (
    <g>
      <rect x={left} y={top} width={w} height={bottom - top} rx={12} fill={BODY_COLORS.body} stroke={BODY_COLORS.bodyStroke} strokeWidth={1.5} />
      {/* panoramic windshield across the front + destination sign */}
      <rect x={left + 6} y={top + 6} width={w - 12} height={16} rx={6} fill={BODY_COLORS.glass} />
      <rect x={left + w * 0.3} y={top + 25} width={w * 0.4} height={5} rx={2} fill={BODY_COLORS.dark} opacity={0.6} />
      {/* side window strips */}
      <rect x={left + 4} y={top + 40} width={6} height={bottom - top - 70} rx={2} fill={BODY_COLORS.glass} opacity={0.7} />
      <rect x={right - 10} y={top + 40} width={6} height={bottom - top - 70} rx={2} fill={BODY_COLORS.glass} opacity={0.7} />
      {/* roof air-conditioning unit */}
      <rect x={left + w * 0.22} y={acTop} width={w * 0.56} height={56} rx={8} fill={BODY_COLORS.detail} opacity={0.55} />
    </g>
  );
}

/** Shorter than a bus: distinct cabin (hood, windshield, mirrors) with the cargo body merged behind it. */
export function VanBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const w = right - left;
  const hood = 24;
  const cabinEnd = top + hood + 50;
  return (
    <g>
      <rect x={left - 6} y={top + hood + 4} width={6} height={5} rx={1} fill={BODY_COLORS.dark} />
      <rect x={right} y={top + hood + 4} width={6} height={5} rx={1} fill={BODY_COLORS.dark} />
      <path d={roundedFrontPath(left, right, top, bottom, 18, 6)} fill={BODY_COLORS.body} stroke={BODY_COLORS.bodyStroke} strokeWidth={1.5} />
      <path d={`M ${left + 6} ${top + hood} Q ${(left + right) / 2} ${top + 2} ${right - 6} ${top + hood} Z`} fill={BODY_COLORS.detail} opacity={0.35} />
      <Windshield left={left} right={right} y={top + hood + 2} depth={20} inset={8} />
      {/* cabin / cargo boundary, then one continuous roof to the rear doors */}
      <line x1={left + 4} x2={right - 4} y1={cabinEnd} y2={cabinEnd} stroke={BODY_COLORS.bodyStroke} opacity={0.6} />
      <line x1={(left + right) / 2} x2={(left + right) / 2} y1={bottom - 16} y2={bottom} stroke={BODY_COLORS.bodyStroke} />
      <rect x={left + 10} y={bottom - 14} width={w - 20} height={8} rx={2} fill={BODY_COLORS.glass} opacity={0.6} />
    </g>
  );
}

/** Compact chassis, overhead guard, rear counterweight; mast and forks sticking out in front. */
export function ForkliftBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const w = right - left;
  const cx = (left + right) / 2;
  const forkTop = top - g.frontExtension + 6;
  const guardTop = top + 24;
  const guardBottom = Math.min(bottom - 46, guardTop + 90);
  return (
    <g>
      {/* forks + carriage + mast in front of the body */}
      <rect x={cx - 26} y={forkTop} width={9} height={top - forkTop - 10} rx={2} fill={BODY_COLORS.dark} />
      <rect x={cx + 17} y={forkTop} width={9} height={top - forkTop - 10} rx={2} fill={BODY_COLORS.dark} />
      <rect x={left + w * 0.12} y={top - 14} width={w * 0.76} height={10} rx={2} fill={BODY_COLORS.bodyStroke} />
      <rect x={left + w * 0.2} y={top - 6} width={w * 0.6} height={8} fill={BODY_COLORS.detail} />
      {/* chassis with a heavy rounded counterweight at the back */}
      <path
        d={`M ${left + 6} ${top} L ${right - 6} ${top} Q ${right} ${top} ${right} ${top + 6} L ${right} ${bottom - 34} Q ${right} ${bottom} ${cx} ${bottom} Q ${left} ${bottom} ${left} ${bottom - 34} L ${left} ${top + 6} Q ${left} ${top} ${left + 6} ${top} Z`}
        fill={BODY_COLORS.accent}
        stroke={BODY_COLORS.accentStroke}
        strokeWidth={1.5}
      />
      <path d={`M ${left + 8} ${bottom - 30} Q ${cx} ${bottom + 6} ${right - 8} ${bottom - 30}`} fill="none" stroke={BODY_COLORS.accentStroke} strokeWidth={6} opacity={0.55} />
      {/* overhead guard (roof bars) with the seat underneath */}
      <rect x={left + 14} y={guardTop} width={w - 28} height={guardBottom - guardTop} rx={4} fill="none" stroke={BODY_COLORS.dark} strokeWidth={3} />
      {[1, 2, 3].map((i) => (
        <line key={i} x1={left + 14 + ((w - 28) * i) / 4} x2={left + 14 + ((w - 28) * i) / 4} y1={guardTop} y2={guardBottom} stroke={BODY_COLORS.dark} strokeWidth={1.5} opacity={0.6} />
      ))}
      <circle cx={cx} cy={(guardTop + guardBottom) / 2 + 8} r={10} fill={BODY_COLORS.dark} opacity={0.35} />
    </g>
  );
}

/** Generic wheeled machine: chamfered chassis, front hazard bar, central cab, rear engine hood. */
export function HeavyEquipmentBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const w = right - left;
  const c = 14; // chamfer
  const cabTop = g.frontYs.length ? frontEnd(g) + 30 : top + 40;
  const cabBottom = Math.min(cabTop + 76, bottom - 70);
  return (
    <g>
      <path
        d={`M ${left + c} ${top} L ${right - c} ${top} L ${right} ${top + c} L ${right} ${bottom - c} L ${right - c} ${bottom} L ${left + c} ${bottom} L ${left} ${bottom - c} L ${left} ${top + c} Z`}
        fill={BODY_COLORS.accent}
        stroke={BODY_COLORS.accentStroke}
        strokeWidth={3}
      />
      {/* front hazard bar */}
      <rect x={left + c} y={top + 4} width={w - 2 * c} height={9} fill={BODY_COLORS.dark} />
      {Array.from({ length: 7 }, (_, i) => (
        <rect key={i} x={left + c + 4 + i * ((w - 2 * c - 8) / 7)} y={top + 4} width={(w - 2 * c - 8) / 14} height={9} fill={BODY_COLORS.accent} />
      ))}
      {/* cab with glass */}
      <rect x={left + w * 0.22} y={cabTop} width={w * 0.56} height={cabBottom - cabTop} rx={6} fill={BODY_COLORS.bodyStroke} />
      <rect x={left + w * 0.27} y={cabTop + 6} width={w * 0.46} height={16} rx={3} fill={BODY_COLORS.glass} />
      {/* engine hood with vents */}
      <rect x={left + w * 0.18} y={bottom - 62} width={w * 0.64} height={48} rx={6} fill="none" stroke={BODY_COLORS.accentStroke} />
      {[0, 1, 2, 3].map((i) => (
        <line key={i} x1={left + w * 0.26} x2={right - w * 0.26} y1={bottom - 52 + i * 9} y2={bottom - 52 + i * 9} stroke={BODY_COLORS.accentStroke} opacity={0.7} />
      ))}
    </g>
  );
}

/** Rigid truck: cab with windshield and mirrors over the steer axle, then a separate cargo box. */
export function TruckBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const w = right - left;
  const cabBottom = top + g.frontOverhang + 30;
  const boxTop = cabBottom + 6;
  return (
    <g>
      <TruckCab left={left} right={right} top={top} bottom={cabBottom} />
      {/* cargo box with roof ribs */}
      <rect x={left} y={boxTop} width={w} height={bottom - boxTop} rx={3} fill={BODY_COLORS.body} stroke={BODY_COLORS.bodyStroke} strokeWidth={1.5} />
      {Array.from({ length: Math.max(0, Math.floor((bottom - boxTop - 20) / 26)) }, (_, i) => (
        <line key={i} x1={left + 6} x2={right - 6} y1={boxTop + 20 + i * 26} y2={boxTop + 20 + i * 26} stroke={BODY_COLORS.detail} opacity={0.7} />
      ))}
      <line x1={(left + right) / 2} x2={(left + right) / 2} y1={bottom - 12} y2={bottom} stroke={BODY_COLORS.bodyStroke} />
    </g>
  );
}

/** Truck cab (also the Semi Trailer tractor): rounded front, windshield, roof, side mirrors. */
function TruckCab({ left, right, top, bottom }: { left: number; right: number; top: number; bottom: number }) {
  const w = right - left;
  return (
    <g>
      <rect x={left - 7} y={top + 16} width={7} height={5} rx={1} fill={BODY_COLORS.dark} />
      <rect x={right} y={top + 16} width={7} height={5} rx={1} fill={BODY_COLORS.dark} />
      <path d={roundedFrontPath(left, right, top, bottom, 14, 4)} fill={BODY_COLORS.body} stroke={BODY_COLORS.bodyStroke} strokeWidth={1.5} />
      <path d={`M ${left + 8} ${top + 10} L ${right - 8} ${top + 10} L ${right - 6} ${top + 24} L ${left + 6} ${top + 24} Z`} fill={BODY_COLORS.glass} />
      <rect x={left + w * 0.2} y={top + 32} width={w * 0.6} height={Math.max(8, bottom - top - 44)} rx={4} fill="none" stroke={BODY_COLORS.detail} />
    </g>
  );
}

/** Long box with a visible frame (edge rails, corner posts, a few cross members) and rear doors. */
function CargoBox({ left, right, top, bottom }: { left: number; right: number; top: number; bottom: number }) {
  const w = right - left;
  const members = Math.max(0, Math.floor((bottom - top) / 70));
  return (
    <g>
      <rect x={left} y={top} width={w} height={bottom - top} rx={2} fill={BODY_COLORS.body} stroke={BODY_COLORS.bodyStroke} strokeWidth={2} />
      <rect x={left + 5} y={top + 5} width={w - 10} height={bottom - top - 10} rx={1} fill="none" stroke={BODY_COLORS.detail} />
      {Array.from({ length: members }, (_, i) => {
        const y = top + ((bottom - top) * (i + 1)) / (members + 1);
        return <line key={i} x1={left + 5} x2={right - 5} y1={y} y2={y} stroke={BODY_COLORS.detail} opacity={0.6} />;
      })}
      {[
        [left, top],
        [right - 7, top],
        [left, bottom - 7],
        [right - 7, bottom - 7],
      ].map(([x, y]) => (
        <rect key={`${x}-${y}`} x={x} y={y} width={7} height={7} fill={BODY_COLORS.dark} />
      ))}
      <line x1={(left + right) / 2} x2={(left + right) / 2} y1={bottom - 14} y2={bottom} stroke={BODY_COLORS.bodyStroke} />
    </g>
  );
}

/**
 * Trailer: framed cargo box + centered triangular tow bar on the front pointing forward. The tow
 * bar length is the engine's front extension (15% of the box length, width unchanged).
 */
export function TrailerBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const cx = (left + right) / 2;
  const eye = top - g.frontExtension + 8;
  const half = (right - left) * 0.32; // A-frame base ≈ 64% of the box width, centered
  return (
    <g>
      <path d={`M ${cx - half} ${top} L ${cx} ${eye + 6} L ${cx + half} ${top} Z`} fill={BODY_COLORS.detail} opacity={0.3} />
      <path d={`M ${cx - half} ${top + 1} L ${cx} ${eye + 6} L ${cx + half} ${top + 1}`} fill="none" stroke={BODY_COLORS.dark} strokeWidth={4} strokeLinejoin="round" />
      <circle cx={cx} cy={eye} r={5} fill="none" stroke={BODY_COLORS.dark} strokeWidth={3} />
      <CargoBox left={left} right={right} top={top} bottom={bottom} />
    </g>
  );
}

/**
 * Semi Trailer: short cab-over tractor, a clear gap showing its chassis, and the trailer resting on
 * the fifth wheel. Front group = tractor axles, rear group = trailer axles. The kingpin is marked
 * subtly — the axle/wheel configuration stays the main subject.
 */
export function SemiTrailerBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const w = right - left;
  const cx = (left + right) / 2;
  const firstFront = g.frontYs.length ? g.frontYs[0] : top + g.frontOverhang;
  const cabBottom = firstFront + 12;
  const trailerTop = cabBottom + 32; // visible coupling gap between tractor and trailer
  const lastFront = frontEnd(g);
  const coupling = Math.max(g.frontYs.length >= 2 ? lastFront - 2 : lastFront + 24, trailerTop + 12);
  const chassisEnd = Math.max(coupling + 30, lastFront + 30);
  return (
    <g>
      <rect x={cx - w * 0.22} y={cabBottom - 4} width={w * 0.44} height={chassisEnd - cabBottom + 4} rx={3} fill={BODY_COLORS.dark} opacity={0.75} />
      <TruckCab left={left} right={right} top={top} bottom={cabBottom} />
      <CargoBox left={left} right={right} top={trailerTop} bottom={bottom} />
      <circle cx={cx} cy={coupling} r={6} fill="none" stroke={BODY_COLORS.bodyStroke} strokeWidth={1.5} strokeDasharray="3 2" opacity={0.7} />
    </g>
  );
}

const BODY_RENDERERS: Record<BodyStyle, (props: BodyProps) => ReactNode> = {
  PASSENGER_CAR: PassengerCarBody,
  BUS: BusBody,
  VAN: VanBody,
  FORKLIFT: ForkliftBody,
  HEAVY_EQUIPMENT: HeavyEquipmentBody,
  TRUCK: TruckBody,
  TRAILER: TrailerBody,
  SEMI_TRAILER: SemiTrailerBody,
};

export function VehicleBody({ g }: BodyProps) {
  const Renderer = BODY_RENDERERS[g.style];
  return <Renderer g={g} />;
}
