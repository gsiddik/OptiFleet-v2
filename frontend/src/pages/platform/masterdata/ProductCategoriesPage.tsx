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
import { t as tt } from '../../../i18n/i18n';

const ITEM_TYPES: ItemType[] = ['SPARE_PART', 'TOOL', 'TIRE', 'CONSUMABLE', 'EQUIPMENT', 'RIM', 'OTHER'];

/**
 * "Next Improvement Tenant Portal - Products": "Fitur Product Categories
 * hanya dikelola oleh Superadmin" — Product Category management lives
 * only in the platform portal. Tenants have no Product Categories menu; they
 * only read categories through the product forms (GET /app/product-categories).
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
    { key: 'code', header: tt('common.fields.code'), render: (c) => c.code },
    { key: 'name', header: tt('common.fields.name'), render: (c) => c.name },
    { key: 'parent_id', header: tt('common.fields.parent'), render: (c) => (c.parent_id ? data.find((d) => d.id === c.parent_id)?.name ?? '—' : '—') },
    { key: 'item_type', header: tt('common.fields.itemType'), render: (c) => c.item_type ?? tt('common.fields.any') },
    { key: 'status', header: tt('common.fields.status'), render: (c) => <StatusBadge status={c.status} /> },
    {
      key: 'actions',
      header: '',
      render: (c) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('product_category.update') && (
            <button className="btn-link" onClick={() => setEditing(c)}>
              {tt('common.actions.edit')}
            </button>
          )}
          {hasPermission('product_category.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(c)}>
              {tt('common.actions.delete')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.masterdata.titles.productCategories')}</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('product_category.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('platform.masterdata.actions.newCategory')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('platform.masterdata.empty.noProductCategoriesFound')} />}
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
        title={tt('platform.masterdata.confirm.deleteProductCategory')}
        message={deleteError ?? tt('platform.masterdata.confirm.deleteNameCannotUndone', { name: deleting?.name })}
        confirmLabel={tt('common.actions.delete')}
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
    <Modal open={open} title={category ? tt('platform.masterdata.modals.editProductCategory') : tt('platform.masterdata.modals.newProductCategory')} onClose={onClose}>
      <FormField label={tt('common.fields.code')} errors={errors.code} required={!category}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!category} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      {!category && (
        <FormField label={tt('platform.masterdata.fields.parentCategory')} errors={errors.parent_id}>
          <select value={parentId} onChange={(e) => setParentId(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.fields.noneTopLevel')}</option>
            {topLevelOptions.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      <FormField label={tt('common.fields.itemType')} errors={errors.item_type}>
        {isSubcategory ? (
          <input value={category?.item_type ?? tt('platform.masterdata.fields.inheritedFromParent')} disabled style={{ ...inputStyle, color: '#888' }} />
        ) : (
          <select value={itemType} onChange={(e) => setItemType(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.filters.anyItemType')}</option>
            {ITEM_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        )}
      </FormField>
      <FormField label={tt('common.fields.description')} errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !name || (!category && !code)} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
