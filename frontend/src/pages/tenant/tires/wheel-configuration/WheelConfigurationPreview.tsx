import { VehicleBody } from './VehicleBodies';
import type { BodyStyle } from './vehicleTypes';
import { WHEEL, buildPreviewGeometry, type PreviewGeometry, type PreviewInput } from './wheelPreviewGeometry';

const COLORS = {
  tire: '#1f2937',
  tireText: '#f9fafb',
  axle: '#374151',
  pending: '#9ca3af',
  spare: '#4b5563',
  label: '#6b7280',
  front: '#1d4ed8',
};

/**
 * Top view of the configured vehicle, generated from the form data. One renderer for every
 * vehicle type: layout engine (wheelPreviewGeometry) → axle lines → body (VehicleBodies, per type)
 * → wheels with position codes → spare tires. Drawing order matters: the body hides the middle of
 * each axle line and the outermost wheel covers its end, so no line ever shows past a tire.
 */
export function WheelConfigurationPreview({ input, bodyStyle = 'PASSENGER_CAR' }: { input: PreviewInput; bodyStyle?: BodyStyle }) {
  const g = buildPreviewGeometry(input, bodyStyle);

  return (
    <svg
      viewBox={`0 0 ${g.width} ${g.height}`}
      role="img"
      aria-label="Vehicle wheel configuration preview, top view, front at the top"
      data-body-style={g.style}
      style={{ width: '100%', maxWidth: g.width * 1.6, maxHeight: '72vh', display: 'block', margin: '0 auto' }}
    >
      <FrontMarker g={g} />
      <AxleLines g={g} />
      <VehicleBody g={g} />
      <AxleLabels g={g} />
      <Wheels g={g} />
      <SpareTires g={g} />
    </svg>
  );
}

function FrontMarker({ g }: { g: PreviewGeometry }) {
  return (
    <g>
      <text x={g.centerX} y={g.frontMarkerY} textAnchor="middle" fontSize={11} fontWeight={700} fill={COLORS.front} letterSpacing={1}>
        FRONT
      </text>
      <path d={`M ${g.centerX - 7} ${g.frontMarkerY + 16} L ${g.centerX} ${g.frontMarkerY + 6} L ${g.centerX + 7} ${g.frontMarkerY + 16} Z`} fill={COLORS.front} />
    </g>
  );
}

function AxleLines({ g }: { g: PreviewGeometry }) {
  return (
    <g>
      {g.axles.map((a) => (
        <line
          key={a.label}
          data-axle={a.label}
          x1={a.x1}
          x2={a.x2}
          y1={a.y}
          y2={a.y}
          stroke={a.complete ? COLORS.axle : COLORS.pending}
          strokeWidth={4}
          strokeDasharray={a.complete ? undefined : '6 4'}
          strokeLinecap="butt"
        />
      ))}
    </g>
  );
}

function AxleLabels({ g }: { g: PreviewGeometry }) {
  return (
    <g>
      {g.axles.map((a) => (
        <text key={a.label} x={6} y={a.y + 3} fontSize={9} fill={COLORS.label}>
          {a.label}
        </text>
      ))}
      {g.wheelbase && (
        // On a light pill so it stays readable on any body.
        <g>
          <rect x={g.centerX - 28} y={(g.wheelbase.y1 + g.wheelbase.y2) / 2 - 7} width={56} height={14} rx={7} fill="#ffffff" opacity={0.85} />
          <text x={g.centerX} y={(g.wheelbase.y1 + g.wheelbase.y2) / 2 + 3} textAnchor="middle" fontSize={9} fill={COLORS.label}>
            wheelbase
          </text>
        </g>
      )}
    </g>
  );
}

function Wheels({ g }: { g: PreviewGeometry }) {
  return (
    <g>
      {g.wheels.map((w) => (
        <g key={w.code} data-wheel={w.code}>
          <title>{w.code}</title>
          <rect x={w.x} y={w.y} width={WHEEL.width} height={WHEEL.length} rx={WHEEL.radius} fill={COLORS.tire} />
          <text
            x={w.x + WHEEL.width / 2}
            y={w.y + WHEEL.length / 2}
            transform={`rotate(-90 ${w.x + WHEEL.width / 2} ${w.y + WHEEL.length / 2})`}
            textAnchor="middle"
            dominantBaseline="central"
            fontSize={7.5}
            fontWeight={600}
            fill={COLORS.tireText}
          >
            {w.code}
          </text>
        </g>
      ))}
    </g>
  );
}

/** Spare tires: separate, outside the body, not on an axle. */
function SpareTires({ g }: { g: PreviewGeometry }) {
  return (
    <g>
      {g.spareLabel && (
        <text x={g.spareLabel.x} y={g.spareLabel.y} textAnchor="middle" fontSize={9} fill={COLORS.label}>
          Spare Tires
        </text>
      )}
      {g.spares.map((s) => (
        <g key={s.code} data-spare={s.code}>
          <title>{`Spare tire ${s.code}`}</title>
          <rect x={s.x} y={s.y} width={WHEEL.length} height={WHEEL.width} rx={WHEEL.radius} fill={COLORS.spare} />
          <text x={s.x + WHEEL.length / 2} y={s.y + WHEEL.width / 2} textAnchor="middle" dominantBaseline="central" fontSize={8} fontWeight={600} fill={COLORS.tireText}>
            {s.code}
          </text>
        </g>
      ))}
    </g>
  );
}
