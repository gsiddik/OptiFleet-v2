import { formatMoney } from '../../utils/money';

export interface KpiResult {
  code: string;
  label: string;
  unit: string;
  description: string;
  formula: string;
  numerator: number | null;
  denominator: number | null;
  value: number | null;
  period: { from: string; to: string };
}

function formatValue(kpi: KpiResult): string {
  if (kpi.value === null || kpi.value === undefined) return '—';
  const rounded = Math.round(kpi.value * 100) / 100;
  if (kpi.unit === 'percentage') return `${rounded}%`;
  if (kpi.unit === 'currency') return formatMoney(kpi.value);
  return rounded.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

// Section 45/55: the card always shows the underlying numerator/denominator,
// never only a rounded percentage, and the exact formula is one hover away.
export function KpiCard({ kpi }: { kpi: KpiResult }) {
  return (
    <div className="card" style={{ padding: 16, minWidth: 200 }} title={kpi.formula}>
      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>{kpi.label}</div>
      <div style={{ fontSize: 26, fontWeight: 700, color: '#111827' }}>{formatValue(kpi)}</div>
      {kpi.numerator !== null && kpi.denominator !== null && (
        <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 4 }}>
          {kpi.numerator.toLocaleString()} / {kpi.denominator.toLocaleString()}
        </div>
      )}
    </div>
  );
}
