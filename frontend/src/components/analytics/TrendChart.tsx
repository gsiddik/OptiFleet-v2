import { t } from '../../i18n/i18n';
interface Point {
  date: string;
  value: number | null;
}

// No charting library in this project's dependencies; a plain inline SVG
// line is enough for a trend and avoids adding a dependency for one
// chart type (Section 45: practical chart types, not decorative ones).
export function TrendChart({ points, label }: { points: Point[]; label: string }) {
  const values = points.map((p) => p.value).filter((v): v is number => v !== null);
  if (values.length === 0) {
    return <div style={{ fontSize: 13, color: '#9ca3af', padding: 16 }}>{t('common.empty.noTrendDataPeriod')}</div>;
  }

  const width = 640;
  const height = 160;
  const padding = 24;
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;

  const step = points.length > 1 ? (width - padding * 2) / (points.length - 1) : 0;
  const coords = points.map((p, i) => {
    const x = padding + i * step;
    const y = p.value === null ? null : height - padding - ((p.value - min) / range) * (height - padding * 2);
    return { x, y, value: p.value, date: p.date };
  });

  const linePath = coords
    .filter((c) => c.y !== null)
    .map((c, i) => `${i === 0 ? 'M' : 'L'} ${c.x} ${c.y}`)
    .join(' ');

  return (
    <div className="card" style={{ padding: 16 }}>
      <div style={{ fontSize: 13, color: '#374151', marginBottom: 8, fontWeight: 600 }}>{label}</div>
      <svg viewBox={`0 0 ${width} ${height}`} style={{ width: '100%', height }} role="img" aria-label={t('common.tooltips.labelTrend', { label: label })}>
        <line x1={padding} y1={height - padding} x2={width - padding} y2={height - padding} stroke="#e5e7eb" />
        <path d={linePath} fill="none" stroke="#2563eb" strokeWidth={2} />
        {coords.map(
          (c, i) =>
            c.y !== null && (
              <circle key={i} cx={c.x} cy={c.y} r={2.5} fill="#2563eb">
                <title>
                  {c.date}: {c.value}
                </title>
              </circle>
            ),
        )}
      </svg>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#9ca3af' }}>
        <span>{points[0]?.date}</span>
        <span>{points[points.length - 1]?.date}</span>
      </div>
    </div>
  );
}
