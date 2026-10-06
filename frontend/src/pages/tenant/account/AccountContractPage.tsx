import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import type { ContractItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import { t } from '../../../i18n/i18n';

export function AccountContractPage() {
  const [contracts, setContracts] = useState<ContractItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/account/contracts')
      .then((res) => setContracts(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, []);

  if (error) return <ErrorState message={error} />;
  if (!contracts) return <LoadingState />;

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>{t('nav.items.contracts')}</h1>
      {contracts.length === 0 && <div className="card" style={{ color: '#9ca3af' }}>{t('platform.contracts.empty.noContractsFound')}</div>}
      {contracts.map((c) => (
        <div key={c.id} className="card" style={{ marginBottom: 14 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
            <strong style={{ fontSize: 15 }}>{c.contract_number}</strong>
            <StatusBadge status={c.status} />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 10, fontSize: 13, color: '#374151' }}>
            <div>{t('account.fields.startStartDate', { start_date: c.start_date })}</div>
            <div>{t('account.fields.endEndDate', { end_date: c.end_date })}</div>
            <div>{t('account.fields.billingCycleBillingCycle', { billing_cycle: c.billing_cycle })}</div>
            <div>
              {t('common.fields.total')}: {c.currency} {formatMoney(c.total)}
            </div>
          </div>
          {c.items && c.items.length > 0 && (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginTop: 12 }}>
              <thead>
                <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
                  <th style={{ padding: '6px 8px' }}>{t('account.fields.item')}</th>
                  <th style={{ padding: '6px 8px' }}>{t('common.fields.qty')}</th>
                  <th style={{ padding: '6px 8px' }}>{t('common.fields.amount')}</th>
                </tr>
              </thead>
              <tbody>
                {c.items.map((it) => (
                  <tr key={it.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                    <td style={{ padding: '6px 8px' }}>{it.description}</td>
                    <td style={{ padding: '6px 8px' }}>{formatQty(it.quantity)}</td>
                    <td style={{ padding: '6px 8px' }}>{formatMoney(it.final_amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      ))}
    </div>
  );
}
