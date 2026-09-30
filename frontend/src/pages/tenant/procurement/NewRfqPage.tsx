import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { NumericInput } from '../../../components/NumericInput';
import { Pagination } from '../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { ProductItem, Warehouse } from '../../../types';

interface Option {
  id: string;
  name: string;
  parent_id?: string | null;
}

interface Selected {
  product: ProductItem;
  quantity: string;
}

function allowsFraction(product: ProductItem): boolean {
  return Boolean(product.uom?.allows_fractional_quantity);
}

/** Quantity rule for a selected line: required, > 0, whole number for counted products. */
function quantityError(line: Selected): string | null {
  const qty = Number(line.quantity);
  if (line.quantity.trim() === '' || !Number.isFinite(qty) || qty <= 0) return 'Enter a quantity greater than 0.';
  if (!allowsFraction(line.product) && !Number.isInteger(qty)) return 'Whole numbers only for this product.';
  return null;
}

function compatibilityLabel(product: ProductItem): string {
  const rows = product.compatibilities ?? [];
  if (rows.length === 0) return '—';
  const labels = rows.map((c) => [c.vehicle_brand, c.vehicle_model].filter(Boolean).join(' ') || 'Universal');
  const unique = [...new Set(labels)];
  return unique.length > 3 ? `${unique.slice(0, 3).join(', ')} +${unique.length - 3}` : unique.join(', ');
}

/**
 * New RFQ (dedicated page, replaces the old modal): destination warehouse, then pick any number of
 * active products — searchable by name and filterable by Product Category and Vehicle Model — each
 * with its own quantity. Saved as a DRAFT RFQ; the backend re-validates every line.
 */
export function NewRfqPage() {
  const navigate = useNavigate();
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [categories, setCategories] = useState<Option[]>([]);
  const [brands, setBrands] = useState<Option[]>([]);
  const [models, setModels] = useState<Option[]>([]);
  const [warehouseId, setWarehouseId] = useState('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [brandId, setBrandId] = useState('');
  const [modelId, setModelId] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Record<string, Selected>>({});
  const [showErrors, setShowErrors] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    apiClient.get('/app/warehouses', { params: { status: 'ACTIVE', per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
    apiClient.get('/app/product-categories', { params: { per_page: 200 } }).then((res) => setCategories(res.data.data)).catch(() => setCategories([]));
    apiClient.get('/app/product-classification/vehicle-brands').then((res) => setBrands(res.data.data)).catch(() => setBrands([]));
  }, []);

  useEffect(() => {
    if (!brandId) return;
    apiClient
      .get('/app/product-classification/vehicle-models', { params: { vehicle_brand_id: brandId } })
      .then((res) => setModels(res.data.data))
      .catch(() => setModels([]));
  }, [brandId]);

  useEffect(() => {
    const t = setTimeout(() => {
      setDebouncedSearch(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [search]);

  const { data: products, meta, loading, error: listError } = useApiList<ProductItem>('/app/products', {
    status: 'ACTIVE',
    search: debouncedSearch || undefined,
    category_id: categoryId || undefined,
    vehicle_model_id: modelId || undefined,
    per_page: 20,
    page,
  });

  // Top-level categories first, each followed by its subcategories.
  const categoryOptions = useMemo(() => {
    const roots = categories.filter((c) => !c.parent_id);
    return roots.flatMap((root) => [{ ...root, label: root.name }, ...categories.filter((c) => c.parent_id === root.id).map((c) => ({ ...c, label: `— ${c.name}` }))]);
  }, [categories]);

  const lines = Object.values(selected);
  const invalidLines = lines.filter((l) => quantityError(l) !== null);

  function toggle(product: ProductItem, checked: boolean) {
    setSelected((prev) => {
      const next = { ...prev };
      if (checked) next[product.id] = { product, quantity: prev[product.id]?.quantity ?? '1' };
      else delete next[product.id];
      return next;
    });
  }

  function setQuantity(productId: string, quantity: string) {
    setSelected((prev) => (prev[productId] ? { ...prev, [productId]: { ...prev[productId], quantity } } : prev));
  }

  async function save() {
    setShowErrors(true);
    setError(null);
    setFieldErrors({});
    if (!warehouseId || lines.length === 0 || invalidLines.length > 0) return;
    setSaving(true);
    try {
      const res = await apiClient.post('/app/rfqs', {
        warehouse_id: warehouseId,
        items: lines.map((l) => ({ product_id: l.product.id, quantity: l.quantity })),
      });
      navigate(`/app/rfqs/${res.data.data.id}`);
    } catch (err) {
      const apiError = extractApiError(err);
      setError(apiError.message);
      setFieldErrors(apiError.errors ?? {});
    } finally {
      setSaving(false);
    }
  }

  const lineServerError = (productId: string) => {
    const index = lines.findIndex((l) => l.product.id === productId);
    return fieldErrors[`items.${index}.quantity`]?.[0] ?? fieldErrors[`items.${index}.product_id`]?.[0];
  };

  return (
    <div>
      <BackButton fallbackTo="/app/rfqs" label="← Back to RFQs" />
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>New RFQ</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0, marginBottom: 16 }}>
        Choose the destination warehouse and the products to request. The RFQ is saved as a Draft; invite vendors on the next page.
      </p>
      {error && <ErrorState message={error} />}

      <div style={{ maxWidth: 360 }}>
        <FormField label="Warehouse" errors={fieldErrors.warehouse_id ?? (showErrors && !warehouseId ? ['Select the destination warehouse.'] : undefined)} required>
          <select aria-label="Warehouse" value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
            <option value="">Select warehouse…</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </FormField>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 8, marginBottom: 12 }}>
        <input aria-label="Search product name" placeholder="Search product name…" value={search} onChange={(e) => setSearch(e.target.value)} style={inputStyle} />
        <select
          aria-label="Product Category"
          value={categoryId}
          onChange={(e) => {
            setCategoryId(e.target.value);
            setPage(1);
          }}
          style={inputStyle}
        >
          <option value="">All categories</option>
          {categoryOptions.map((c) => (
            <option key={c.id} value={c.id}>
              {c.label}
            </option>
          ))}
        </select>
        <select
          aria-label="Vehicle Brand"
          value={brandId}
          onChange={(e) => {
            setBrandId(e.target.value);
            setModelId('');
            setModels([]);
            setPage(1);
          }}
          style={inputStyle}
        >
          <option value="">Any vehicle brand</option>
          {brands.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
        <select
          aria-label="Vehicle Model"
          value={modelId}
          disabled={!brandId}
          onChange={(e) => {
            setModelId(e.target.value);
            setPage(1);
          }}
          style={inputStyle}
        >
          <option value="">{brandId ? 'Any model' : 'Select a brand first'}</option>
          {models.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name}
            </option>
          ))}
        </select>
      </div>

      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && products.length === 0 && <EmptyState label="No products match these filters." />}
      {!listError && !loading && products.length > 0 && (
        <div style={{ overflowX: 'auto', border: '1px solid #e5e7eb', borderRadius: 8 }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 640 }}>
            <thead>
              <tr style={{ background: '#f9fafb', textAlign: 'left' }}>
                <th style={{ padding: 8, width: 36 }} />
                <th style={{ padding: 8 }}>Code</th>
                <th style={{ padding: 8 }}>Product Name</th>
                <th style={{ padding: 8 }}>Product Category</th>
                <th style={{ padding: 8 }}>Vehicle Compatibility</th>
                <th style={{ padding: 8, width: 120 }}>Quantity</th>
              </tr>
            </thead>
            <tbody>
              {products.map((p) => {
                const line = selected[p.id];
                const lineError = line && showErrors ? quantityError(line) ?? lineServerError(p.id) : line ? lineServerError(p.id) : null;
                return (
                  <tr key={p.id} style={{ borderTop: '1px solid #f3f4f6', background: line ? '#eff6ff' : undefined }}>
                    <td style={{ padding: 8 }}>
                      <input type="checkbox" aria-label={`Select ${p.name}`} checked={Boolean(line)} onChange={(e) => toggle(p, e.target.checked)} />
                    </td>
                    <td style={{ padding: 8, color: '#6b7280' }}>{p.sku || p.code}</td>
                    <td style={{ padding: 8 }}>{p.name}</td>
                    <td style={{ padding: 8 }}>{p.category?.name ?? '—'}</td>
                    <td style={{ padding: 8, color: '#6b7280' }}>{compatibilityLabel(p)}</td>
                    <td style={{ padding: 8 }}>
                      {line && (
                        <>
                          <NumericInput
                            aria-label={`Quantity for ${p.name}`}
                            integer={!allowsFraction(p)}
                            value={line.quantity}
                            onChange={(e) => setQuantity(p.id, e.target.value)}
                            style={{ ...inputStyle, width: 90, borderColor: lineError ? '#dc2626' : undefined }}
                          />
                          {p.uom && <span style={{ fontSize: 11, color: '#6b7280', marginLeft: 4 }}>{p.uom.code}</span>}
                          {lineError && <div style={{ color: '#b91c1c', fontSize: 11, marginTop: 2 }}>{lineError}</div>}
                        </>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}

      <div className="card" style={{ marginTop: 16 }}>
        <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 8 }}>Selected products ({lines.length})</div>
        {lines.length === 0 && (
          <div style={{ fontSize: 13, color: showErrors ? '#b91c1c' : '#6b7280' }}>Select at least one product.</div>
        )}
        {lines.map((l) => (
          <div key={l.product.id} style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13, padding: '4px 0', flexWrap: 'wrap' }}>
            <span style={{ flex: '1 1 200px' }}>{l.product.name}</span>
            <NumericInput
              aria-label={`Selected quantity for ${l.product.name}`}
              integer={!allowsFraction(l.product)}
              value={l.quantity}
              onChange={(e) => setQuantity(l.product.id, e.target.value)}
              style={{ ...inputStyle, width: 90 }}
            />
            <button type="button" className="btn-link" onClick={() => toggle(l.product, false)}>
              Remove
            </button>
            {showErrors && quantityError(l) && <span style={{ color: '#b91c1c', fontSize: 11, flexBasis: '100%' }}>{quantityError(l)}</span>}
          </div>
        ))}
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button className="btn-secondary" onClick={() => navigate('/app/rfqs')} disabled={saving}>
          Cancel
        </button>
        <button className="btn-primary" onClick={save} disabled={saving}>
          {saving ? 'Saving…' : 'Save as Draft'}
        </button>
      </div>
    </div>
  );
}
