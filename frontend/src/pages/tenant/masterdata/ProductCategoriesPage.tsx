import { useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ProductCategoryItem } from '../../../types';

export function ProductCategoriesPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<ProductCategoryItem | null>(null);
  const [deleting, setDeleting] = useState<ProductCategoryItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const { data, meta, loading, error } = useApiList<ProductCategoryItem>(
    '/app/product-categories',
    { search, page, per_page: 15 },
    reloadKey,
  );

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/app/product-categories/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const columns: Column<ProductCategoryItem>[] = [
    { key: 'code', header: 'Code', render: (c) => c.code },
    { key: 'name', header: 'Name', render: (c) => c.name },
    { key: 'is_system', header: 'Source', render: (c) => (c.is_system ? 'System' : 'Tenant') },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
    {
      key: 'actions',
      header: '',
      render: (c) =>
        !c.is_system && (
          <div style={{ display: 'flex', gap: 8 }}>
            {hasPermission('product.update') && (
              <button className="btn-link" onClick={() => setEditing(c)}>
                Edit
              </button>
            )}
            {hasPermission('product.delete') && (
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(c)}>
                Delete
              </button>
            )}
          </div>
        ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Product Categories</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('product.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Category
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No product categories found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <CategoryFormModal open={showCreate} onClose={() => setShowCreate(false)} onSaved={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <CategoryFormModal
          open
          category={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title="Delete Product Category"
        message={deleteError ?? `Delete "${deleting?.name}"? This cannot be undone.`}
        confirmLabel="Delete"
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function CategoryFormModal({
  open,
  category,
  onClose,
  onSaved,
}: {
  open: boolean;
  category?: ProductCategoryItem;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(category?.code ?? '');
  const [name, setName] = useState(category?.name ?? '');
  const [description, setDescription] = useState(category?.description ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      if (category) {
        await apiClient.put(`/app/product-categories/${category.id}`, { name, description });
      } else {
        await apiClient.post('/app/product-categories', { code, name, description });
      }
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title={category ? 'Edit Product Category' : 'New Product Category'} onClose={onClose}>
      <FormField label="Code" errors={errors.code} required={!category}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!category} />
      </FormField>
      <FormField label="Name" errors={errors.name} required={!category}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Description" errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
