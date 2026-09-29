import { useCallback, useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { ErrorState } from '../../../components/States';
import type { Warehouse, WarehouseBinItem, WarehouseRackItem, WarehouseZoneItem } from '../../../types';

type Node = WarehouseZoneItem | WarehouseRackItem | WarehouseBinItem;

interface Level {
  key: 'zone' | 'rack' | 'bin';
  title: string;
  endpoint: string;
  parentField: 'warehouse_id' | 'warehouse_zone_id' | 'warehouse_rack_id';
}

const LEVELS: Level[] = [
  { key: 'zone', title: 'Zones', endpoint: '/app/warehouse-zones', parentField: 'warehouse_id' },
  { key: 'rack', title: 'Racks', endpoint: '/app/warehouse-racks', parentField: 'warehouse_zone_id' },
  { key: 'bin', title: 'Bins', endpoint: '/app/warehouse-bins', parentField: 'warehouse_rack_id' },
];

/**
 * Warehouse storage layout (Zone -> Rack -> Bin). The backend has always had full
 * CRUD for these, but no screen exposed it — and a Default Storage Bin is mandatory
 * for every new Product, so without this a tenant could not create Products at all.
 */
export function WarehouseStorageLayoutModal({ warehouse, canManage, onClose }: { warehouse: Warehouse; canManage: boolean; onClose: () => void }) {
  const [zoneId, setZoneId] = useState('');
  const [rackId, setRackId] = useState('');
  const [error, setError] = useState<string | null>(null);

  return (
    <Modal open title={`Storage Layout — ${warehouse.name}`} onClose={onClose} width={980}>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>
        Products are stored in a Bin (Warehouse → Zone → Rack → Bin). Select a Zone to see its Racks, and a Rack to see its Bins.
      </p>
      {error && <ErrorState message={error} />}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 12 }}>
        <LevelColumn level={LEVELS[0]} parentId={warehouse.id} selectedId={zoneId} onSelect={(id) => { setZoneId(id); setRackId(''); }} canManage={canManage} onError={setError} />
        <LevelColumn level={LEVELS[1]} parentId={zoneId} selectedId={rackId} onSelect={setRackId} canManage={canManage} onError={setError} emptyHint="Select a Zone" />
        <LevelColumn level={LEVELS[2]} parentId={rackId} canManage={canManage} onError={setError} emptyHint="Select a Rack" />
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 16 }}>
        <button className="btn-secondary" onClick={onClose}>
          Close
        </button>
      </div>
    </Modal>
  );
}

function LevelColumn({
  level,
  parentId,
  selectedId,
  onSelect,
  canManage,
  onError,
  emptyHint,
}: {
  level: Level;
  parentId: string;
  selectedId?: string;
  onSelect?: (id: string) => void;
  canManage: boolean;
  onError: (message: string | null) => void;
  emptyHint?: string;
}) {
  const [rows, setRows] = useState<Node[]>([]);
  const [loadedFor, setLoadedFor] = useState('');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const load = useCallback(() => {
    if (!parentId) return;
    apiClient
      .get(level.endpoint, { params: { [level.parentField]: parentId, per_page: 200 } })
      .then((res) => {
        setRows(res.data.data);
        setLoadedFor(parentId);
      })
      .catch((err) => onError(extractApiError(err).message));
  }, [level, parentId, onError]);

  useEffect(load, [load]);
  const visible = parentId && loadedFor === parentId ? rows : [];

  async function run(fn: () => Promise<unknown>) {
    setBusy(true);
    onError(null);
    try {
      await fn();
      load();
    } catch (err) {
      const e = extractApiError(err);
      setFieldErrors(e.errors ?? {});
      onError(e.errors ? Object.values(e.errors).flat()[0] ?? e.message : e.message);
    } finally {
      setBusy(false);
    }
  }

  function add() {
    run(async () => {
      setFieldErrors({});
      await apiClient.post(level.endpoint, { [level.parentField]: parentId, code, name });
      setCode('');
      setName('');
    });
  }

  function rename(row: Node) {
    const next = window.prompt(`Rename ${row.code}`, row.name);
    if (next && next !== row.name) run(() => apiClient.put(`${level.endpoint}/${row.id}`, { name: next }));
  }

  return (
    <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 10, minWidth: 0 }}>
      <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 8 }}>{level.title}</div>
      {!parentId ? (
        <div style={{ fontSize: 12, color: '#9ca3af' }}>{emptyHint}</div>
      ) : (
        <>
          <div style={{ maxHeight: 280, overflowY: 'auto' }}>
            {visible.length === 0 && <div style={{ fontSize: 12, color: '#9ca3af', padding: '4px 0' }}>None yet.</div>}
            {visible.map((row) => (
              <div
                key={row.id}
                onClick={() => onSelect?.(row.id)}
                style={{
                  padding: '5px 6px', borderRadius: 6, fontSize: 13,
                  cursor: onSelect ? 'pointer' : 'default', background: selectedId === row.id ? '#eff6ff' : 'transparent',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ fontFamily: 'monospace', fontWeight: 600 }}>{row.code}</span>
                  <span style={{ flex: 1, minWidth: 60, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{row.name}</span>
                  <StatusBadge status={row.status} />
                </div>
                {canManage && (
                  <span style={{ display: 'flex', gap: 8, fontSize: 12 }} onClick={(e) => e.stopPropagation()}>
                    <button className="btn-link" disabled={busy} onClick={() => rename(row)}>
                      Rename
                    </button>
                    <button
                      className="btn-link"
                      disabled={busy}
                      onClick={() => run(() => apiClient.put(`${level.endpoint}/${row.id}`, { status: row.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' }))}
                    >
                      {row.status === 'ACTIVE' ? 'Deactivate' : 'Activate'}
                    </button>
                    <button
                      className="btn-link"
                      style={{ color: '#b91c1c' }}
                      disabled={busy}
                      onClick={() => window.confirm(`Delete ${row.code}?`) && run(() => apiClient.delete(`${level.endpoint}/${row.id}`))}
                    >
                      Delete
                    </button>
                  </span>
                )}
              </div>
            ))}
          </div>
          {canManage && (
            <div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}>
              <input aria-label={`${level.title} code`} placeholder="Code" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} style={{ ...inputStyle, width: 90, borderColor: fieldErrors.code ? '#b91c1c' : undefined }} />
              <input aria-label={`${level.title} name`} placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} style={{ ...inputStyle, flex: 1, minWidth: 100, borderColor: fieldErrors.name ? '#b91c1c' : undefined }} />
              <button className="btn-secondary" disabled={busy || !code || !name} onClick={add}>
                {busy ? '…' : 'Add'}
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
