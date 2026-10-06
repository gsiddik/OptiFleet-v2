import { useEffect, useRef, useState, type KeyboardEvent, type MouseEvent } from 'react';
import { VehicleBody } from './VehicleBodies';
import type { BodyStyle } from './vehicleTypes';
import { describePosition } from './wheelLayout';
import { WHEEL, buildPreviewGeometry, type PreviewGeometry, type PreviewInput } from './wheelPreviewGeometry';
import { t } from '../../../../i18n/i18n';

const COLORS = {
  tire: '#1f2937',
  tireText: '#f9fafb',
  selected: '#2563eb',
  axle: '#374151',
  pending: '#9ca3af',
  spare: '#4b5563',
  label: '#6b7280',
  front: '#1d4ed8',
};

/** Below this rendered width (phone) the codes are not printed on the tires — tap to see them. */
const COMPACT_WIDTH_PX = 420;

type Selection = { code: string; description: string; left: number; top: number };

/**
 * Top view of the configured vehicle, generated from the form data. One renderer for every
 * vehicle type: layout engine (wheelPreviewGeometry) → axle lines → body (VehicleBodies, per type)
 * → wheels with position codes → spare tires. Drawing order matters: the body hides the middle of
 * each axle line and the outermost wheel covers its end, so no line ever shows past a tire.
 *
 * Position codes: printed on the tires on desktop/tablet; on a phone-width preview they are hidden
 * and every tire is tappable — a small popover shows its code (no hover needed), and an optional
 * position list sits under the preview.
 */
export function WheelConfigurationPreview({
  input,
  bodyStyle = 'PASSENGER_CAR',
  selectedCode,
  onPositionSelect,
  installedCodes,
  positionColors,
  readOnly = false,
}: {
  input: PreviewInput;
  bodyStyle?: BodyStyle;
  /** select mode (Vehicle Detail): the parent owns the selection and shows details next to the preview */
  selectedCode?: string | null;
  onPositionSelect?: (code: string) => void;
  /** positions that have an active tire — drawn with a subtle marker, layout unchanged */
  installedCodes?: ReadonlySet<string>;
  /** multi-selection colouring: tires listed here are filled with their colour (e.g. replacement, rotation pairs) */
  positionColors?: Readonly<Record<string, string>>;
  /** display only: no click, focus, keyboard or popover — nothing on the preview can change anything */
  readOnly?: boolean;
}) {
  const g = buildPreviewGeometry(input, bodyStyle);
  const selectMode = onPositionSelect !== undefined;
  const wrapper = useRef<HTMLDivElement>(null);
  const [compact, setCompact] = useState(false);
  const [selected, setSelected] = useState<Selection | null>(null);

  useEffect(() => {
    const el = wrapper.current;
    if (!el || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) => {
      setCompact(entry.contentRect.width < COMPACT_WIDTH_PX);
      setSelected(null); // positions move on resize
    });
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  function select(target: Element, code: string, description: string) {
    if (onPositionSelect) {
      onPositionSelect(code);
      return;
    }
    const box = wrapper.current?.getBoundingClientRect();
    const tire = target.getBoundingClientRect();
    if (!box) return;
    setSelected((current) => (current?.code === code ? null : { code, description, left: tire.left + tire.width / 2 - box.left, top: tire.top - box.top }));
  }

  const tireHandlers = (code: string, description: string): Record<string, unknown> =>
    readOnly
      ? { 'aria-label': `${code} — ${description}`, style: { cursor: 'default' } }
      : {
    role: 'button',
    tabIndex: 0,
    'aria-label': `${code} — ${description}`,
    style: { cursor: 'pointer', outline: 'none' },
    onClick: (e: MouseEvent<SVGGElement>) => {
      e.stopPropagation();
      select(e.currentTarget, code, description);
    },
    onKeyDown: (e: KeyboardEvent<SVGGElement>) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        select(e.currentTarget, code, description);
      }
      if (e.key === 'Escape') setSelected(null);
    },
  };

  return (
    <div ref={wrapper} style={{ position: 'relative' }} onClick={() => setSelected(null)} data-compact={compact ? 'true' : 'false'} data-read-only={readOnly ? 'true' : undefined}>
      <svg
        viewBox={`0 0 ${g.width} ${g.height}`}
        role="img"
        aria-label={t('tire.tooltips.vehicleWheelConfigurationPreviewTopView')}
        data-body-style={g.style}
        style={{ width: '100%', maxWidth: g.width * 1.6, maxHeight: '72vh', display: 'block', margin: '0 auto' }}
      >
        <FrontMarker g={g} />
        <AxleLines g={g} />
        <VehicleBody g={g} />
        <AxleLabels g={g} />
        <Wheels g={g} showCodes={!compact} selected={selectMode ? (selectedCode ?? null) : (selected?.code ?? null)} installed={installedCodes} colors={positionColors} handlers={tireHandlers} />
        <SpareTires g={g} showCodes={!compact} selected={selectMode ? (selectedCode ?? null) : (selected?.code ?? null)} installed={installedCodes} colors={positionColors} handlers={tireHandlers} />
      </svg>

      {selected && (
        <div
          role="status"
          data-position-popover={selected.code}
          onClick={(e) => e.stopPropagation()}
          style={{
            position: 'absolute',
            left: selected.left,
            top: selected.top - 6,
            transform: 'translate(-50%, -100%)',
            background: '#111827',
            color: '#f9fafb',
            borderRadius: 6,
            padding: '6px 9px',
            fontSize: 12,
            lineHeight: 1.35,
            whiteSpace: 'nowrap',
            boxShadow: '0 4px 12px rgba(0,0,0,0.2)',
            zIndex: 2,
            pointerEvents: 'auto',
          }}
        >
          <strong style={{ fontSize: 13 }}>{selected.code}</strong>
          <div style={{ color: '#d1d5db', fontSize: 11 }}>{selected.description}</div>
        </div>
      )}

      {compact && !selectMode && !readOnly && <PositionList g={g} />}
    </div>
  );
}

type Handlers = (code: string, description: string) => Record<string, unknown>;

function FrontMarker({ g }: { g: PreviewGeometry }) {
  return (
    <g>
      <text x={g.centerX} y={g.frontMarkerY} textAnchor="middle" fontSize={11} fontWeight={700} fill={COLORS.front} letterSpacing={1}>
        {t('tire.fields.front')}
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
            {t('tire.fields.wheelbase')}
          </text>
        </g>
      )}
    </g>
  );
}

const INSTALLED = '#22c55e';

function Wheels({ g, showCodes, selected, installed, colors, handlers }: { g: PreviewGeometry; showCodes: boolean; selected: string | null; installed?: ReadonlySet<string>; colors?: Readonly<Record<string, string>>; handlers: Handlers }) {
  return (
    <g>
      {g.wheels.map((w) => (
        <g key={w.code} data-wheel={w.code} data-installed={installed?.has(w.code) ? 'true' : undefined} data-color={colors?.[w.code]} {...handlers(w.code, describePosition(w))}>
          <title>{w.code}</title>
          <rect
            x={w.x}
            y={w.y}
            width={WHEEL.width}
            height={WHEEL.length}
            rx={WHEEL.radius}
            fill={colors?.[w.code] ?? COLORS.tire}
            stroke={selected === w.code ? COLORS.selected : colors?.[w.code] ? '#111827' : installed?.has(w.code) ? INSTALLED : 'none'}
            strokeWidth={selected === w.code || colors?.[w.code] ? 3 : 2}
          />
          {installed?.has(w.code) && <circle cx={w.x + WHEEL.width / 2} cy={w.y - 4} r={2.5} fill={INSTALLED} pointerEvents="none" />}
          {showCodes && (
            <text
              x={w.x + WHEEL.width / 2}
              y={w.y + WHEEL.length / 2}
              transform={`rotate(-90 ${w.x + WHEEL.width / 2} ${w.y + WHEEL.length / 2})`}
              textAnchor="middle"
              dominantBaseline="central"
              fontSize={7.5}
              fontWeight={600}
              fill={COLORS.tireText}
              pointerEvents="none"
            >
              {w.code}
            </text>
          )}
        </g>
      ))}
    </g>
  );
}

/** Spare tires: separate, outside the body, not on an axle. */
function SpareTires({ g, showCodes, selected, installed, colors, handlers }: { g: PreviewGeometry; showCodes: boolean; selected: string | null; installed?: ReadonlySet<string>; colors?: Readonly<Record<string, string>>; handlers: Handlers }) {
  return (
    <g>
      {g.spareLabel && (
        <text x={g.spareLabel.x} y={g.spareLabel.y} textAnchor="middle" fontSize={9} fill={COLORS.label}>
          {t('tire.fields.spareTires')}
        </text>
      )}
      {g.spares.map((s) => (
        <g key={s.code} data-spare={s.code} data-installed={installed?.has(s.code) ? 'true' : undefined} data-color={colors?.[s.code]} {...handlers(s.code, t('tire.fields.spareTireCode', { code: s.code.slice(1) }))}>
          <title>{t('tire.fields.spareTireCode', { code: s.code })}</title>
          <rect
            x={s.x}
            y={s.y}
            width={WHEEL.length}
            height={WHEEL.width}
            rx={WHEEL.radius}
            fill={colors?.[s.code] ?? COLORS.spare}
            stroke={selected === s.code ? COLORS.selected : colors?.[s.code] ? '#111827' : installed?.has(s.code) ? INSTALLED : 'none'}
            strokeWidth={selected === s.code || colors?.[s.code] ? 3 : 2}
          />
          {installed?.has(s.code) && <circle cx={s.x - 5} cy={s.y + WHEEL.width / 2} r={2.5} fill={INSTALLED} pointerEvents="none" />}
          {showCodes && (
            <text x={s.x + WHEEL.length / 2} y={s.y + WHEEL.width / 2} textAnchor="middle" dominantBaseline="central" fontSize={8} fontWeight={600} fill={COLORS.tireText} pointerEvents="none">
              {s.code}
            </text>
          )}
        </g>
      ))}
    </g>
  );
}

/** Optional list of every position, per axle (left outer → inner | right inner → outer) — phone width only. */
function PositionList({ g }: { g: PreviewGeometry }) {
  const rows = g.axles.map((a) => {
    const wheels = g.wheels.filter((w) => `${w.group}${w.axle}` === a.label);
    const left = wheels.filter((w) => w.side === 'L').sort((x, y) => y.index - x.index);
    const right = wheels.filter((w) => w.side === 'R').sort((x, y) => x.index - y.index);
    return { label: a.label, left: left.map((w) => w.code), right: right.map((w) => w.code) };
  });
  return (
    <details data-position-list style={{ marginTop: 8, fontSize: 12 }} onClick={(e) => e.stopPropagation()}>
      <summary style={{ cursor: 'pointer', color: '#374151', fontWeight: 600 }}>{t('tire.sections.positionList')}</summary>
      <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 6 }}>
        <tbody>
          {rows.map((r) => (
            <tr key={r.label} style={{ borderTop: '1px solid #f3f4f6' }}>
              <td style={{ padding: '4px 6px', color: '#6b7280', width: 32 }}>{r.label}</td>
              <td style={{ padding: '4px 6px', textAlign: 'right', fontFamily: 'monospace' }}>{r.left.join(' ') || '—'}</td>
              <td style={{ padding: '4px 6px', fontFamily: 'monospace' }}>{r.right.join(' ') || '—'}</td>
            </tr>
          ))}
          {g.spares.length > 0 && (
            <tr style={{ borderTop: '1px solid #f3f4f6' }}>
              <td style={{ padding: '4px 6px', color: '#6b7280' }}>{t('tire.help.spare')}</td>
              <td colSpan={2} style={{ padding: '4px 6px', fontFamily: 'monospace' }}>
                {g.spares.map((s) => s.code).join(' ')}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </details>
  );
}
