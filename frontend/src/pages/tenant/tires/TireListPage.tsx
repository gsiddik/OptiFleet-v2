import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { TireItem } from '../../../types';

const STATUSES = ['', 'IN_STOCK', 'RESERVED', 'INSTALLED', 'IN_USE', 'REMOVED', 'UNDER_INSPECTION', 'RETREAD', 'SCRAPPED', 'LOST'];

export function TireListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const { data, loading, error } = useApiList<TireItem>('/app/tires', { current_status: status || undefined, search: search || undefined }, 0);

  const columns: Column<TireItem>[] = [
    { key: 'serial', header: 'Serial', render: (t) => <Link to={`/app/tires/${t.id}`}>{t.serial_number}</Link> },
    { key: 'product', header: 'Product', render: (t) => t.product?.name ?? t.product_id },
    { key: 'size', header: 'Size', render: (t) => t.tire_size ?? '—' },
    { key: 'vehicle', header: 'Vehicle', render: (t) => t.current_vehicle?.registration_number ?? '—' },
    { key: 'position', header: 'Position', render: (t) => t.current_position ?? '—' },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.current_status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Tires</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 11 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('product.view') ? (
            // Tires are created through Product (Item Type = Tire), the source of truth for the
            // tire specification; a physical tire is then registered from that product's page.
            <span style={{ fontSize: 12, color: '#6b7280' }}>
              New tires are created from <Link to="/app/products?product_type=TIRE">Products (Item Type: Tire)</Link>
            </span>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No tires found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

    </div>
  );
}
