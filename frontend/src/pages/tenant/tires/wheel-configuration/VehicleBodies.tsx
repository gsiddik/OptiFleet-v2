import type { ReactNode } from 'react';
import type { BodyStyle } from './vehicleTypes';
import type { PreviewGeometry } from './wheelPreviewGeometry';

/**
 * Body renderers — one silhouette per vehicle type, drawn from the shared layout geometry
 * (PROTOTYPE designs for owner review). Each draws only the body: axle lines, wheels, spare tires
 * and labels come from WheelConfigurationPreview, so wheel/axle rules are identical for all types.
 * Every body is drawn inside g.body (top = front of the vehicle) plus g.profile.frontExtension
 * above it for parts that stick out in front.
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
  return g.frontYs.length ? g.frontYs[g.frontYs.length - 1] : g.body.top + g.profile.nose;
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

/** Long box with a flat, glazed front, roof equipment and side window strips. */
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
      {/* roof: air-conditioning unit and hatches */}
      <rect x={left + w * 0.22} y={acTop} width={w * 0.56} height={56} rx={8} fill={BODY_COLORS.detail} opacity={0.55} />
      <rect x={left + w * 0.38} y={top + 50} width={w * 0.24} height={18} rx={3} fill="none" stroke={BODY_COLORS.detail} />
      <rect x={left + w * 0.38} y={bottom - 72} width={w * 0.24} height={18} rx={3} fill="none" stroke={BODY_COLORS.detail} />
      {/* rear engine grille */}
      {[0, 1, 2].map((i) => (
        <line key={i} x1={left + w * 0.3} x2={right - w * 0.3} y1={bottom - 22 + i * 5} y2={bottom - 22 + i * 5} stroke={BODY_COLORS.detail} />
      ))}
    </g>
  );
}

/** Compact, tall body with a short hood, windshield, roof rails and split rear doors. */
export function VanBody({ g }: BodyProps) {
  const { top, bottom, left, right } = g.body;
  const w = right - left;
  const hood = 26;
  return (
    <g>
      <path d={roundedFrontPath(left, right, top, bottom, 18, 6)} fill={BODY_COLORS.body} stroke={BODY_COLORS.bodyStroke} strokeWidth={1.5} />
      <path d={`M ${left + 6} ${top + hood} Q ${(left + right) / 2} ${top + 2} ${right - 6} ${top + hood} Z`} fill={BODY_COLORS.detail} opacity={0.35} />
      <Windshield left={left} right={right} y={top + hood + 2} depth={20} inset={8} />
      {/* roof with rails */}
      <rect x={left + 10} y={top + hood + 30} width={w - 20} height={bottom - top - hood - 50} rx={4} fill="none" stroke={BODY_COLORS.detail} />
      <line x1={left + 18} x2={left + 18} y1={top + hood + 40} y2={bottom - 30} stroke={BODY_COLORS.dark} strokeWidth={2} opacity={0.5} />
      <line x1={right - 18} x2={right - 18} y1={top + hood + 40} y2={bottom - 30} stroke={BODY_COLORS.dark} strokeWidth={2} opacity={0.5} />
      {/* split rear doors */}
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
  const forkTop = top - g.profile.frontExtension + 6;
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
        strokeWidth={1.5}
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
  const cabBottom = top + g.profile.nose + 30;
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

const BODY_RENDERERS: Partial<Record<BodyStyle, (props: BodyProps) => ReactNode>> = {
  PASSENGER_CAR: PassengerCarBody,
  BUS: BusBody,
  VAN: VanBody,
  FORKLIFT: ForkliftBody,
  HEAVY_EQUIPMENT: HeavyEquipmentBody,
  TRUCK: TruckBody,
};

export function VehicleBody({ g }: BodyProps) {
  const Renderer = BODY_RENDERERS[g.style] ?? PassengerCarBody;
  return <Renderer g={g} />;
}
