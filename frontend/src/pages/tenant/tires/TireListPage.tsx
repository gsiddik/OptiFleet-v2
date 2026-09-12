import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ProductItem, TireItem } from '../../../types';

const STATUSES = ['', 'IN_STOCK', 'RESERVED', 'INSTALLED', 'IN_USE', 'REMOVED', 'UNDER_INSPECTION', 'RETREAD', 'SCRAPPED', 'LOST'];

export function TireListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<TireItem>('/app/tires', { current_status: status || undefined, search: search || undefined }, reloadKey);

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
          hasPermission('tire.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Tire
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No tires found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateTireModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateTireModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [productId, setProductId] = useState('');
  const [serialNumber, setSerialNumber] = useState('');
  const [manufacturer, setManufacturer] = useState('');
  const [manufactureDateCode, setManufactureDateCode] = useState('');
  const [tireSize, setTireSize] = useState('');
  const [sectionWidthMm, setSectionWidthMm] = useState('');
  const [aspectRatio, setAspectRatio] = useState('');
  const [rimDiameterInch, setRimDiameterInch] = useState('');
  const [loadIndex, setLoadIndex] = useState('');
  const [speedRating, setSpeedRating] = useState('');
  const [plyRating, setPlyRating] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/products', { params: { per_page: 100, product_type: 'TIRE' } }).then((res) => setProducts(res.data.data)).catch(() => setProducts([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/tires', {
        product_id: productId, serial_number: serialNumber, manufacturer: manufacturer || undefined,
        manufacture_date_code: manufactureDateCode || undefined, tire_size: tireSize || undefined,
        section_width_mm: sectionWidthMm || undefined, aspect_ratio: aspectRatio || undefined,
        rim_diameter_inch: rimDiameterInch || undefined, load_index: loadIndex || undefined,
        speed_rating: speedRating || undefined, ply_rating: plyRating || undefined,
      });
      setSerialNumber('');
      setManufacturer('');
      setManufactureDateCode('');
      setTireSize('');
      setSectionWidthMm('');
      setAspectRatio('');
      setRimDiameterInch('');
      setLoadIndex('');
      setSpeedRating('');
      setPlyRating('');
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="New Tire" onClose={onClose}>
      <FormField label="Tire Product" errors={errors.product_id}>
        <select value={productId} onChange={(e) => setProductId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {products.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Serial Number" errors={errors.serial_number}>
        <input value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Manufacturer" errors={errors.manufacturer}>
        <input value={manufacturer} onChange={(e) => setManufacturer(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Manufacture Date Code" errors={errors.manufacture_date_code}>
        <input value={manufactureDateCode} onChange={(e) => setManufactureDateCode(e.target.value)} placeholder="e.g. DOT week/year code" style={inputStyle} />
      </FormField>
      <FormField label="Tire Size" errors={errors.tire_size}>
        <input value={tireSize} onChange={(e) => setTireSize(e.target.value)} placeholder="e.g. 295/80R22.5" style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10 }}>
        <FormField label="Section Width (mm)" errors={errors.section_width_mm}>
          <input type="number" min="1" value={sectionWidthMm} onChange={(e) => setSectionWidthMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Aspect Ratio (%)" errors={errors.aspect_ratio}>
          <input type="number" min="1" max="100" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Rim Diameter (in)" errors={errors.rim_diameter_inch}>
          <input type="number" min="1" step="0.1" value={rimDiameterInch} onChange={(e) => setRimDiameterInch(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Load Index" errors={errors.load_index}>
          <input type="number" min="1" value={loadIndex} onChange={(e) => setLoadIndex(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Speed Rating" errors={errors.speed_rating}>
          <input maxLength={2} value={speedRating} onChange={(e) => setSpeedRating(e.target.value.toUpperCase())} style={inputStyle} />
        </FormField>
        <FormField label="Ply Rating" errors={errors.ply_rating}>
          <input type="number" min="1" value={plyRating} onChange={(e) => setPlyRating(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !productId || !serialNumber} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
