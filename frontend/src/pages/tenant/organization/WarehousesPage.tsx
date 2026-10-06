import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { Branch, Warehouse, Workshop } from '../../../types';
import { WarehouseStorageLayoutModal } from './WarehouseStorageLayoutModal';
import { t as tt } from '../../../i18n/i18n';

const TYPES: Warehouse['warehouse_type'][] = ['CENTRAL', 'BRANCH', 'WORKSHOP', 'TIRE', 'CONSUMABLE', 'SCRAP', 'QUARANTINE'];

export function WarehousesPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<Warehouse | null>(null);
  const [layoutFor, setLayoutFor] = useState<Warehouse | null>(null);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [workshops, setWorkshops] = useState<Workshop[]>([]);

  useEffect(() => {
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => setWorkshops(res.data.data));
  }, []);

  const { data, meta, loading, error } = useApiList<Warehouse>('/app/warehouses', { search, page, per_page: 10 }, reloadKey);

  async function toggleStatus(w: Warehouse) {
    const action = w.status === 'ACTIVE' ? 'deactivate' : 'activate';
    await apiClient.post(`/app/warehouses/${w.id}/${action}`);
    setReloadKey((k) => k + 1);
  }

  const columns: Column<Warehouse>[] = [
    { key: 'code', header: tt('common.fields.code'), render: (w) => w.code },
    { key: 'name', header: tt('common.fields.name'), render: (w) => w.name },
    { key: 'warehouse_type', header: tt('common.fields.type'), render: (w) => w.warehouse_type },
    { key: 'status', header: tt('common.fields.status'), render: (w) => <StatusBadge status={w.status} /> },
    {
      key: 'actions',
      header: '',
      render: (w) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn-link" onClick={() => setLayoutFor(w)}>
            {tt('organization.actions.storageLayout')}
          </button>
          {hasPermission('warehouse.update') && (
            <button className="btn-link" onClick={() => setEditing(w)}>
              {tt('common.actions.edit')}
            </button>
          )}
          {(hasPermission('warehouse.activate') || hasPermission('warehouse.deactivate')) && (
            <button className="btn-link" onClick={() => toggleStatus(w)}>
              {w.status === 'ACTIVE' ? tt('common.actions.deactivate') : tt('common.actions.activate')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('organization.titles.warehouses')}</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('warehouse.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('organization.actions.newWarehouse')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('organization.empty.noWarehousesFound')} />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <WarehouseFormModal
        open={showCreate}
        branches={branches}
        workshops={workshops}
        onClose={() => setShowCreate(false)}
        onSaved={() => setReloadKey((k) => k + 1)}
      />
      {editing && (
        <WarehouseFormModal
          open
          warehouse={editing}
          branches={branches}
          workshops={workshops}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {layoutFor && <WarehouseStorageLayoutModal warehouse={layoutFor} canManage={hasPermission('warehouse.update')} onClose={() => setLayoutFor(null)} />}
    </div>
  );
}

function WarehouseFormModal({
  open,
  warehouse,
  branches,
  workshops,
  onClose,
  onSaved,
}: {
  open: boolean;
  warehouse?: Warehouse;
  branches: Branch[];
  workshops: Workshop[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(warehouse?.code ?? '');
  const [name, setName] = useState(warehouse?.name ?? '');
  const [branchId, setBranchId] = useState(warehouse?.branch_id ?? '');
  const [workshopId, setWorkshopId] = useState(warehouse?.workshop_id ?? '');
  const [type, setType] = useState(warehouse?.warehouse_type ?? 'BRANCH');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    const payload = { code, name, branch_id: branchId || null, workshop_id: workshopId || null, warehouse_type: type };
    try {
      if (warehouse) {
        await apiClient.put(`/app/warehouses/${warehouse.id}`, payload);
      } else {
        await apiClient.post('/app/warehouses', payload);
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
    <Modal open={open} title={warehouse ? tt('organization.modals.editWarehouse') : tt('organization.modals.newWarehouse')} onClose={onClose}>
      <FormField label={tt('common.fields.code')} errors={errors.code} required={!warehouse}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!warehouse} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required={!warehouse}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.branch')} errors={errors.branch_id}>
        <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
          <option value="">{tt('masterData.fields.none')}</option>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('common.fields.workshop')} errors={errors.workshop_id}>
        <select value={workshopId} onChange={(e) => setWorkshopId(e.target.value)} style={inputStyle}>
          <option value="">{tt('masterData.fields.none')}</option>
          {workshops.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('common.fields.type')} errors={errors.warehouse_type}>
        <select value={type} onChange={(e) => setType(e.target.value as Warehouse['warehouse_type'])} style={inputStyle}>
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
