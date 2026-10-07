import { t } from '../i18n/i18n';

export type ValuationStatus = 'VERIFIED' | 'VERIFIED_ZERO' | 'NOT_VALUED' | 'UNVERIFIED' | 'MIXED';

export const VALUATION_ORDER: ValuationStatus[] = ['VERIFIED', 'VERIFIED_ZERO', 'NOT_VALUED', 'UNVERIFIED', 'MIXED'];

const STYLE: Record<ValuationStatus, { color: string; background: string; icon: string }> = {
  VERIFIED: { color: '#166534', background: '#dcfce7', icon: '✓' },
  VERIFIED_ZERO: { color: '#115e59', background: '#ccfbf1', icon: '0' },
  NOT_VALUED: { color: '#374151', background: '#e5e7eb', icon: '–' },
  UNVERIFIED: { color: '#92400e', background: '#fef3c7', icon: '?' },
  MIXED: { color: '#9a3412', background: '#ffedd5', icon: '≈' },
};

export function valuationLabel(status: string): string {
  return t(`valuation.status.${status}`);
}

/** Valuation status of a stock balance. The definition is the tooltip; the icon keeps it readable without colour. */
export function ValuationStatusBadge({ status }: { status: string | null | undefined }) {
  const key = (status && status in STYLE ? status : 'UNVERIFIED') as ValuationStatus;
  const s = STYLE[key];

  return (
    <span
      title={t(`valuation.definition.${key}`)}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '2px 8px', borderRadius: 999, fontSize: 12, fontWeight: 600, color: s.color, background: s.background, whiteSpace: 'nowrap' }}
    >
      <span aria-hidden="true">{s.icon}</span>
      {valuationLabel(key)}
    </span>
  );
}
