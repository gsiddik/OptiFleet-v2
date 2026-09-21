import { useState } from 'react';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { ProductCategoryItem } from '../../../types';

/**
 * "Next Improvement Tenant Portal - Products": "Fitur Product Categories
 * hanya dikelola oleh Superadmin" — this page is read-only for tenants.
 * Management (create/update/delete) is a platform-portal-only feature.
 */
export function ProductCategoriesPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const { data, meta, loading, error } = useApiList<ProductCategoryItem>(
    '/app/product-categories',
    { search, page, per_page: 15 },
  );

  const columns: Column<ProductCategoryItem>[] = [
    { key: 'code', header: 'Code', render: (c) => c.code },
    { key: 'name', header: 'Name', render: (c) => c.name },
    { key: 'item_type', header: 'Item Type', render: (c) => c.item_type ?? 'Any' },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Product Categories</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0, marginBottom: 16 }}>
        Managed by your platform administrator. Contact support to add or change a category.
      </p>
      <Toolbar search={search} onSearchChange={(v) => { setSearch(v); setPage(1); }} />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No product categories found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}
    </div>
  );
}
