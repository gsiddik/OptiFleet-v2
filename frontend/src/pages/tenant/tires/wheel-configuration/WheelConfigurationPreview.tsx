import { WHEEL, buildPreviewGeometry, type PreviewInput } from './wheelPreviewGeometry';

const COLORS = {
  body: '#e5e7eb',
  bodyStroke: '#6b7280',
  glass: '#bfdbfe',
  tire: '#1f2937',
  tireText: '#f9fafb',
  axle: '#374151',
  pending: '#9ca3af',
  spare: '#4b5563',
  label: '#6b7280',
  front: '#1d4ed8',
};

/**
 * Top view of the configured vehicle, generated from the form data (see wheelPreviewGeometry).
 * Drawing order matters: axle lines first, then the body, then the wheels — the body hides the
 * middle of each axle and the outermost wheel covers the axle's end.
 */
export function WheelConfigurationPreview({ input }: { input: PreviewInput }) {
  const g = buildPreviewGeometry(input);
  const { top, bottom, left, right } = g.body;
  const nose = 26; // depth of the rounded front
  const bodyPath = [
    `M ${left} ${top + nose}`,
    `Q ${left} ${top} ${g.centerX} ${top}`,
    `Q ${right} ${top} ${right} ${top + nose}`,
    `L ${right} ${bottom - 10}`,
    `Q ${right} ${bottom} ${right - 10} ${bottom}`,
    `L ${left + 10} ${bottom}`,
    `Q ${left} ${bottom} ${left} ${bottom - 10}`,
    'Z',
  ].join(' ');
  const inset = 12;

  return (
    <svg
      viewBox={`0 0 ${g.width} ${g.height}`}
      role="img"
      aria-label="Vehicle wheel configuration preview, top view, front at the top"
      style={{ width: '100%', maxWidth: g.width * 1.6, maxHeight: '72vh', display: 'block', margin: '0 auto' }}
    >
      {/* Front direction */}
      <text x={g.centerX} y={14} textAnchor="middle" fontSize={11} fontWeight={700} fill={COLORS.front} letterSpacing={1}>
        FRONT
      </text>
      <path d={`M ${g.centerX - 7} ${30} L ${g.centerX} ${20} L ${g.centerX + 7} ${30} Z`} fill={COLORS.front} />

      {/* Axle lines (under body and wheels) */}
      {g.axles.map((a) => (
        <line key={a.label} x1={a.x1} x2={a.x2} y1={a.y} y2={a.y} stroke={a.complete ? COLORS.axle : COLORS.pending} strokeWidth={4} strokeDasharray={a.complete ? undefined : '6 4'} strokeLinecap="butt" />
      ))}

      {/* Body: rounded nose at the front, windshield, rear window */}
      <path d={bodyPath} fill={COLORS.body} stroke={COLORS.bodyStroke} strokeWidth={1.5} />
      <path
        d={`M ${left + inset + 6} ${g.windshield.top} L ${right - inset - 6} ${g.windshield.top} L ${right - inset} ${g.windshield.bottom} L ${left + inset} ${g.windshield.bottom} Z`}
        fill={COLORS.glass}
        opacity={0.9}
      />
      <rect x={left + inset} y={bottom - 30} width={right - left - 2 * inset} height={12} rx={3} fill={COLORS.glass} opacity={0.6} />

      {/* Axle labels (left margin) and wheelbase marker */}
      {g.axles.map((a) => (
        <text key={`l-${a.label}`} x={6} y={a.y + 3} fontSize={9} fill={COLORS.label}>
          {a.label}
        </text>
      ))}
      {g.wheelbase && (
        <text x={g.centerX} y={(g.wheelbase.y1 + g.wheelbase.y2) / 2 + 3} textAnchor="middle" fontSize={9} fill={COLORS.label}>
          wheelbase
        </text>
      )}

      {/* Wheels */}
      {g.wheels.map((w) => (
        <g key={w.code}>
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

      {/* Spare tires: separate, outside the body, not on an axle */}
      {g.spareLabel && (
        <text x={g.spareLabel.x} y={g.spareLabel.y} textAnchor="middle" fontSize={9} fill={COLORS.label}>
          Spare Tires
        </text>
      )}
      {g.spares.map((s) => (
        <g key={s.code}>
          <title>{`Spare tire ${s.code}`}</title>
          <rect x={s.x} y={s.y} width={WHEEL.length} height={WHEEL.width} rx={WHEEL.radius} fill={COLORS.spare} />
          <text x={s.x + WHEEL.length / 2} y={s.y + WHEEL.width / 2} textAnchor="middle" dominantBaseline="central" fontSize={8} fontWeight={600} fill={COLORS.tireText}>
            {s.code}
          </text>
        </g>
      ))}
    </svg>
  );
}
