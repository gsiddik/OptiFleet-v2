import { t } from '../../../i18n/i18n';
import { formatDate } from '../../../utils/date';
import type { DataBasisInfo } from './types';

/**
 * What a figure is based on, under every affected widget: the date each part is dated by (always visible),
 * a completeness badge when the data is partial or unavailable (never silent), and a "how this is calculated"
 * disclosure with the records counted / not counted, the exclusion reasons with their counts and the date the
 * history starts. Plain text and a native <details>, so it is keyboard and screen-reader friendly.
 */
export function BasisNote({ basis }: { basis: DataBasisInfo | null | undefined }) {
  if (!basis) return null;
  const c = basis.completeness;
  const flagged = c !== null && c.status !== 'COMPLETE';
  const dates = basis.date_basis.map((d) => t(`dashboard.basis.date.${d.code}`)).join(' · ');
  return (
    <div className="dash-basis">
      {dates && <div className="dash-basis-line"><span className="dash-basis-key">{t('dashboard.basis.datedBy')}</span> {dates}</div>}
      {c && flagged && (
        <div className={`dash-completeness dash-completeness-${c.status.toLowerCase()}`} role="status">
          <span aria-hidden="true">{c.status === 'UNAVAILABLE' ? '⊘' : '◐'}</span>{' '}
          {t(`dashboard.basis.status.${c.status}`, { valid: c.valid, total: c.total, excluded: c.excluded })}
        </div>
      )}
      <details className="dash-basis-details">
        <summary>{t('dashboard.basis.how')}</summary>
        {basis.includes.length > 0 && (
          <div><strong>{t('dashboard.basis.includes')}</strong>
            <ul>{basis.includes.map((code) => <li key={code}>{t(`dashboard.basis.item.${code}`)}</li>)}</ul></div>
        )}
        {basis.excludes.length > 0 && (
          <div><strong>{t('dashboard.basis.excludes')}</strong>
            <ul>{basis.excludes.map((code) => <li key={code}>{t(`dashboard.basis.item.${code}`)}</li>)}</ul></div>
        )}
        {c && (
          <div><strong>{t('dashboard.basis.completeness')}</strong>
            <ul>
              <li>{t('dashboard.basis.counts', { valid: c.valid, total: c.total, excluded: c.excluded })}</li>
              {c.ongoing > 0 && <li>{t('dashboard.basis.ongoing', { count: c.ongoing })}</li>}
              {c.reasons.map((r) => <li key={r.code}>{t(`dashboard.basis.reason.${r.code}`, { count: r.n })}</li>)}
            </ul></div>
        )}
        {basis.history_from && <div>{t('dashboard.basis.historyFrom', { date: formatDate(basis.history_from) })}</div>}
      </details>
    </div>
  );
}
