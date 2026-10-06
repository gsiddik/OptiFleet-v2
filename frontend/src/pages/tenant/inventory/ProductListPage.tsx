import { useEffect, useState } from 'react';
import { apiClient } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { Link, useSearchParams } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ComponentCategory, ComponentGroup, ComponentSubcategory, ProductItem } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { CreateProductModal, ITEM_TYPES as PRODUCT_TYPES } from './CreateProductModal';
import { ProductImportModal } from './ProductImportModal';
import { t as tt } from '../../../i18n/i18n';

export function ProductListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  // `?product_type=TIRE` (e.g. from Tire List) opens the list already filtered.
  const [searchParams] = useSearchParams();
  const [productType, setProductType] = useState(searchParams.get('product_type') ?? '');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [groupId, setGroupId] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [subcategoryId, setSubcategoryId] = useState('');
  const [groups, setGroups] = useState<ComponentGroup[]>([]);
  const [categories, setCategories] = useState<ComponentCategory[]>([]);
  const [subcategories, setSubcategories] = useState<ComponentSubcategory[]>([]);
  const { data, loading, error } = useApiList<ProductItem>(
    '/app/products',
    {
      search: search || undefined,
      product_type: productType || undefined,
      component_group_id: groupId || undefined,
      component_category_id: categoryId || undefined,
      component_subcategory_id: subcategoryId || undefined,
    },
    reloadKey,
  );

  // Filter lookups include retired rows so historical Products stay findable.
  useEffect(() => {
    apiClient.get('/app/product-classification/component-groups', { params: { include_inactive: 1 } }).then((res) => setGroups(res.data.data)).catch(() => setGroups([]));
  }, []);
  useEffect(() => {
    if (!groupId) return;
    apiClient
      .get('/app/product-classification/categories', { params: { component_group_id: groupId, include_inactive: 1 } })
      .then((res) => setCategories(res.data.data))
      .catch(() => setCategories([]));
  }, [groupId]);
  useEffect(() => {
    if (!categoryId) return;
    apiClient
      .get('/app/product-classification/subcategories', { params: { component_category_id: categoryId, include_inactive: 1 } })
      .then((res) => setSubcategories(res.data.data))
      .catch(() => setSubcategories([]));
  }, [categoryId]);

  const columns: Column<ProductItem>[] = [
    { key: 'code', header: tt('common.fields.code'), render: (p) => <Link to={`/app/products/${p.id}`}>{p.code}</Link> },
    { key: 'name', header: tt('common.fields.name'), render: (p) => p.name },
    { key: 'sku', header: 'SKU', render: (p) => p.sku },
    { key: 'product_type', header: tt('common.fields.type'), render: (p) => p.product_type },
    { key: 'category', header: tt('common.fields.category'), render: (p) => p.category?.name ?? '—' },
    {
      key: 'classification',
      header: tt('inventory.fields.component'),
      render: (p) =>
        p.component_group
          ? [p.component_group.abbreviation ?? p.component_group.name, p.component_category?.name, p.component_subcategory?.name].filter(Boolean).join(' › ')
          : '—',
    },
    { key: 'status', header: tt('common.fields.status'), render: (p) => <StatusBadge status={p.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('inventory.titles.products')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {['', ...PRODUCT_TYPES].map((t) => (
          <button key={t} onClick={() => setProductType(t)} className={productType === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {t || tt('common.actions.all')}
          </button>
        ))}
      </div>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('product.create') ? (
            <span style={{ display: 'flex', gap: 8 }}>
              <button className="btn-secondary" onClick={() => setShowImport(true)} data-product-import>
                {tt('productImport.actions.importProducts')}
              </button>
              <button className="btn-primary" onClick={() => setShowCreate(true)}>
                {tt('inventory.actions.newProduct')}
              </button>
            </span>
          ) : null
        }
      >
        <select
          aria-label={tt('common.fields.componentGroupFilter')}
          value={groupId}
          onChange={(e) => {
            setGroupId(e.target.value);
            setCategoryId('');
            setSubcategoryId('');
            setCategories([]);
            setSubcategories([]);
          }}
          style={{ ...inputStyle, width: 200 }}
        >
          <option value="">{tt('common.filters.allComponentGroups')}</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {componentGroupLabel(g)}
            </option>
          ))}
        </select>
        <select
          aria-label={tt('inventory.fields.componentCategoryFilter')}
          value={categoryId}
          disabled={!groupId}
          onChange={(e) => {
            setCategoryId(e.target.value);
            setSubcategoryId('');
            setSubcategories([]);
          }}
          style={{ ...inputStyle, width: 180 }}
        >
          <option value="">{tt('common.filters.allCategories')}</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
        <select aria-label={tt('inventory.fields.componentSubcategoryFilter')} value={subcategoryId} disabled={!categoryId} onChange={(e) => setSubcategoryId(e.target.value)} style={{ ...inputStyle, width: 180 }}>
          <option value="">{tt('inventory.filters.allSubcategories')}</option>
          {subcategories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>

      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('inventory.empty.noProductsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      {showImport && <ProductImportModal initialItemType={productType} onClose={() => setShowImport(false)} onImported={() => setReloadKey((k) => k + 1)} />}
      <CreateProductModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}
