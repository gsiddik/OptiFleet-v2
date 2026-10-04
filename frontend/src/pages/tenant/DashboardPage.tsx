import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../api/client';
import { ErrorState, LoadingState } from '../../components/States';
import { formatMoney } from '../../utils/money';

interface DashboardData {
  branches_total: number;
  workshops_total: number;
  warehouses_total: number;
  users_total: number;
  active_modules: string[];
  vehicles_total?: number;
  vehicles_active?: number;
  vehicles_in_maintenance?: number;
  vehicles_breakdown?: number;
  maintenance_upcoming?: number;
  maintenance_due_soon?: number;
  maintenance_due?: number;
  maintenance_overdue?: number;
  maintenance_requests_open?: number;
  work_orders_active?: number;
  work_orders_pending_qc?: number;
  workspaces_available?: number;
  workspaces_occupied?: number;
  inventory_total_value?: number;
  inventory_reserved_stock?: number;
  inventory_low_stock?: number;
  inventory_out_of_stock?: number;
  transfers_open?: number;
  transfers_in_transit?: number;
  purchase_requests_open?: number;
  purchase_orders_open?: number;
  goods_receipts_pending?: number;
  tires_in_use?: number;
  tires_due_replacement?: number;
  component_assets_installed?: number;
  warranty_claims_active?: number;
}

export function TenantDashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/dashboard')
      .then((res) => setData(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, []);

  if (error) return <ErrorState message={error} />;
  if (!data) return <LoadingState />;

  const stats = [
    { label: 'Branches', value: data.branches_total },
    { label: 'Workshops', value: data.workshops_total },
    { label: 'Warehouses', value: data.warehouses_total },
    { label: 'Active Users', value: data.users_total },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>Dashboard</h1>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
        {stats.map((s) => (
          <div key={s.label} className="card">
            <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
            <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value}</div>
          </div>
        ))}
      </div>

      {data.active_modules.includes('VEHICLE') && (
        <>
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>Fleet</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: 'Total Vehicles', value: data.vehicles_total },
              { label: 'Active', value: data.vehicles_active },
              { label: 'In Maintenance', value: data.vehicles_in_maintenance },
              { label: 'Breakdown', value: data.vehicles_breakdown },
            ].map((s) => (
              <div key={s.label} className="card">
                <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value ?? 0}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {data.active_modules.includes('MAINTENANCE') && (
        <>
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>Maintenance</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: 'Upcoming', value: data.maintenance_upcoming },
              { label: 'Due Soon', value: data.maintenance_due_soon },
              { label: 'Due', value: data.maintenance_due },
              { label: 'Overdue', value: data.maintenance_overdue },
              { label: 'Open Requests', value: data.maintenance_requests_open },
            ].map((s) => (
              <div key={s.label} className="card">
                <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value ?? 0}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {data.active_modules.includes('WORK_ORDER') && (
        <>
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>Work Orders</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: 'Active', value: data.work_orders_active },
              { label: 'Pending QC', value: data.work_orders_pending_qc },
            ].map((s) => (
              <div key={s.label} className="card">
                <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value ?? 0}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {data.active_modules.includes('WORKSHOP') && (
        <>
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>Workshop Operations</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: 'Workspaces Available', value: data.workspaces_available },
              { label: 'Workspaces Occupied', value: data.workspaces_occupied },
            ].map((s) => (
              <div key={s.label} className="card">
                <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value ?? 0}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {data.active_modules.includes('INVENTORY') && (
        <>
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>Supply Chain — Inventory</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: 'Total Inventory Value', value: formatMoney(data.inventory_total_value) },
              { label: 'Reserved Stock', value: data.inventory_reserved_stock },
              { label: 'Low Stock', value: data.inventory_low_stock },
              { label: 'Out of Stock', value: data.inventory_out_of_stock },
              { label: 'Open Transfers', value: data.transfers_open },
              { label: 'In-Transit Transfers', value: data.transfers_in_transit },
            ].map((s) => (
              <div key={s.label} className="card">
                <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value ?? 0}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {data.active_modules.includes('PROCUREMENT') && (
        <>
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>Supply Chain — Procurement</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: 'Open Purchase Requests', value: data.purchase_requests_open },
              { label: 'Open Purchase Orders', value: data.purchase_orders_open },
              { label: 'Pending Goods Receipt', value: data.goods_receipts_pending },
            ].map((s) => (
              <div key={s.label} className="card">
                <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value ?? 0}</div>
              </div>
            ))}
          </div>
        </>
      )}

      {(data.active_modules.includes('TIRE') || data.active_modules.includes('COMPONENT')) && (
        <>
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>Asset Lifecycle</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              data.active_modules.includes('TIRE') && { label: 'Tires In Use', value: data.tires_in_use },
              data.active_modules.includes('TIRE') && { label: 'Tires Due Replacement', value: data.tires_due_replacement },
              data.active_modules.includes('COMPONENT') && { label: 'Component Assets Installed', value: data.component_assets_installed },
              // Warranty is ORPHANED in the active UI: its claim count is no longer shown (the API still returns it).
            ]
              .filter((s): s is { label: string; value: number | undefined } => Boolean(s))
              .map((s) => (
                <div key={s.label} className="card">
                  <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
                  <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value ?? 0}</div>
                </div>
              ))}
          </div>
        </>
      )}

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>Active Modules</h2>
      <div className="card" style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        {data.active_modules.map((m) => (
          <span key={m} style={{ background: '#eff6ff', color: '#1d4ed8', padding: '4px 10px', borderRadius: 6, fontSize: 12 }}>
            {m}
          </span>
        ))}
        {data.active_modules.length === 0 && <span style={{ color: '#9ca3af' }}>No modules entitled.</span>}
      </div>
    </div>
  );
}
