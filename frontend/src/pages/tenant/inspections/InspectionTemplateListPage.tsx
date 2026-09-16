import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { InspectionTemplateItem } from '../../../types';

const TYPES = ['PRE_TRIP', 'POST_TRIP', 'PERIODIC', 'WORKSHOP', 'MAINTENANCE'];
const INPUT_TYPES = ['CHECKBOX', 'PASS_FAIL', 'TEXT', 'NUMBER', 'SELECT', 'PHOTO'];

export function InspectionTemplateListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<InspectionTemplateItem | null>(null);
  const { data, loading, error } = useApiList<InspectionTemplateItem>('/app/inspection-templates', {}, reloadKey);

  const columns: Column<InspectionTemplateItem>[] = [
    { key: 'name', header: 'Name', render: (t) => <button className="btn-link" onClick={() => setEditing(t)}>{t.name}</button> },
    { key: 'type', header: 'Type', render: (t) => t.inspection_type },
    { key: 'category', header: 'Vehicle Category', render: (t) => t.vehicle_category?.name ?? 'All' },
    { key: 'items', header: 'Items', render: (t) => t.items_count ?? t.items?.length ?? 0 },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Inspection Templates</h1>
      <Toolbar
        actions={
          hasPermission('inspection.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Template
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No templates found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateTemplateModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <TemplateDetailModal
          templateId={editing.id}
          onClose={() => setEditing(null)}
          onChanged={() => setReloadKey((k) => k + 1)}
        />
      )}
    </div>
  );
}

function CreateTemplateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [name, setName] = useState('');
  const [inspectionType, setInspectionType] = useState('PRE_TRIP');
  const [categoryId, setCategoryId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/inspection-templates', {
        name, inspection_type: inspectionType, vehicle_category_id: categoryId || null,
      });
      setName('');
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
    <Modal open={open} title="New Inspection Template" onClose={onClose}>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Inspection Type" errors={errors.inspection_type} required>
        <select value={inspectionType} onChange={(e) => setInspectionType(e.target.value)} style={inputStyle}>
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Vehicle Category (optional — applies to all if blank)">
        <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
          <option value="">All Categories</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !name} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}

function TemplateDetailModal({ templateId, onClose, onChanged }: { templateId: string; onClose: () => void; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [template, setTemplate] = useState<InspectionTemplateItem | null>(null);
  const [itemText, setItemText] = useState('');
  const [inputType, setInputType] = useState('PASS_FAIL');
  const [busy, setBusy] = useState(false);

  function load() {
    apiClient.get(`/app/inspection-templates/${templateId}`).then((res) => setTemplate(res.data.data));
  }

  useEffect(load, [templateId]);

  async function addItem() {
    setBusy(true);
    try {
      await apiClient.post(`/app/inspection-templates/${templateId}/items`, { item_text: itemText, input_type: inputType });
      setItemText('');
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function activate() {
    await apiClient.post(`/app/inspection-templates/${templateId}/activate`);
    load();
    onChanged();
  }

  if (!template) return null;

  return (
    <Modal open title={template.name} onClose={onClose} width={600}>
      <div style={{ marginBottom: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
        <StatusBadge status={template.status} />
        {template.status === 'DRAFT' && hasPermission('inspection.create') && (
          <button className="btn-secondary" onClick={activate}>
            Activate
          </button>
        )}
      </div>
      <table style={{ width: '100%', fontSize: 13, marginBottom: 12, borderCollapse: 'collapse' }}>
        <tbody>
          {(template.items ?? []).map((item) => (
            <tr key={item.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <td style={{ padding: '6px 4px' }}>{item.item_text}</td>
              <td style={{ padding: '6px 4px', color: '#6b7280' }}>{item.input_type}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {hasPermission('inspection.create') && (
        <div style={{ display: 'flex', gap: 8 }}>
          <input placeholder="Checklist item text" value={itemText} onChange={(e) => setItemText(e.target.value)} style={inputStyle} />
          <select value={inputType} onChange={(e) => setInputType(e.target.value)} style={{ ...inputStyle, width: 140 }}>
            {INPUT_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
          <button className="btn-secondary" disabled={busy || !itemText} onClick={addItem}>
            Add
          </button>
        </div>
      )}
    </Modal>
  );
}
