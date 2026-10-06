import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { RfqItem } from '../../../types';
import { statusLabel } from '../../../i18n/statusRegistry';
import { t } from '../../../i18n/i18n';

const STATUSES = ['', 'DRAFT', 'ISSUED', 'CLOSED', 'CANCELLED'];

export function RfqListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const navigate = useNavigate();
  const { data, meta, loading, error } = useApiList<RfqItem>('/app/rfqs', { status: status || undefined, page });

  const columns: Column<RfqItem>[] = [
    { key: 'number', header: t('procurement.fields.rfqNumber'), render: (r) => <Link to={`/app/rfqs/${r.id}`}>{r.rfq_number}</Link> },
    { key: 'warehouse', header: t('common.fields.warehouse'), render: (r) => r.warehouse?.name ?? r.warehouse_id },
    { key: 'vendors', header: t('procurement.fields.vendorsInvited'), render: (r) => r.vendors?.length ?? 0 },
    { key: 'status', header: t('common.fields.status'), render: (r) => <StatusBadge status={r.status} domain="document" /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('procurement.titles.rfqs')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button
            key={s}
            onClick={() => {
              setStatus(s);
              setPage(1);
            }} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s ? statusLabel(s, 'document') : t('common.actions.all')}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('rfq.manage') ? (
            <button className="btn-primary" onClick={() => navigate('/app/rfqs/new')}>
              {t('procurement.actions.newRfq')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('procurement.empty.noRfqsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}

    </div>
  );
}
