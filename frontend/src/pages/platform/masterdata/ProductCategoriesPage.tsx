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
import type { ItemType, ProductCategoryItem } from '../../../types';

const ITEM_TYPES: ItemType[] = ['SPARE_PART', 'TOOL', 'TIRE', 'CONSUMABLE', 'EQUIPMENT', 'RIM', 'OTHER'];

/**
 * "Next Improvement Tenant Portal - Products": "Fitur Product Categories
 * hanya dikelola oleh Superadmin" — Product Category management lives
 * only in the platform portal. Tenants get a read-only list
 * (tenant/masterdata/ProductCategoriesPage.tsx).
 */
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
    '/platform/product-categories',
    { search, page, per_page: 15 },
    reloadKey,
  );

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/platform/product-categories/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const columns: Column<ProductCategoryItem>[] = [
    { key: 'code', header: 'Code', render: (c) => c.code },
    { key: 'name', header: 'Name', render: (c) => c.name },
    { key: 'parent_id', header: 'Parent', render: (c) => (c.parent_id ? data.find((d) => d.id === c.parent_id)?.name ?? '—' : '—') },
    { key: 'item_type', header: 'Item Type', render: (c) => c.item_type ?? 'Any' },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
    {
      key: 'actions',
      header: '',
      render: (c) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('product_category.update') && (
            <button className="btn-link" onClick={() => setEditing(c)}>
              Edit
            </button>
          )}
          {hasPermission('product_category.delete') && (
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
          hasPermission('product_category.create') ? (
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

      <CategoryFormModal open={showCreate} categories={data} onClose={() => setShowCreate(false)} onSaved={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <CategoryFormModal
          open
          category={editing}
          categories={data}
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
  categories,
  onClose,
  onSaved,
}: {
  open: boolean;
  category?: ProductCategoryItem;
  categories: ProductCategoryItem[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(category?.code ?? '');
  const [name, setName] = useState(category?.name ?? '');
  const [parentId, setParentId] = useState(category?.parent_id ?? '');
  const [itemType, setItemType] = useState(category?.item_type ?? '');
  const [description, setDescription] = useState(category?.description ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  const isSubcategory = category ? category.parent_id !== null : !!parentId;
  const topLevelOptions = categories.filter((c) => !c.parent_id && c.id !== category?.id);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      if (category) {
        await apiClient.put(`/platform/product-categories/${category.id}`, { name, item_type: itemType || null, description });
      } else {
        await apiClient.post('/platform/product-categories', {
          code, name, parent_id: parentId || undefined, item_type: itemType || undefined, description,
        });
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
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      {!category && (
        <FormField label="Parent Category" errors={errors.parent_id}>
          <select value={parentId} onChange={(e) => setParentId(e.target.value)} style={inputStyle}>
            <option value="">— None (top-level) —</option>
            {topLevelOptions.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      <FormField label="Item Type" errors={errors.item_type}>
        {isSubcategory ? (
          <input value={category?.item_type ?? 'Inherited from parent'} disabled style={{ ...inputStyle, color: '#888' }} />
        ) : (
          <select value={itemType} onChange={(e) => setItemType(e.target.value)} style={inputStyle}>
            <option value="">Any Item Type</option>
            {ITEM_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        )}
      </FormField>
      <FormField label="Description" errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !name || (!category && !code)} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
