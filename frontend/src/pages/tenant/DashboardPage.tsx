import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../api/client';
import { ErrorState, LoadingState } from '../../components/States';
import { formatMoney } from '../../utils/money';
import { t } from '../../i18n/i18n';

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
    { label: t('dashboard.sections.branches'), value: data.branches_total },
    { label: t('dashboard.sections.workshops'), value: data.workshops_total },
    { label: t('dashboard.sections.warehouses'), value: data.warehouses_total },
    { label: t('dashboard.sections.activeUsers'), value: data.users_total },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>{t('dashboard.titles.dashboard')}</h1>
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
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.fleet')}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: t('dashboard.fields.totalVehicles'), value: data.vehicles_total },
              { label: t('common.fields.active'), value: data.vehicles_active },
              { label: t('dashboard.fields.inMaintenance'), value: data.vehicles_in_maintenance },
              { label: t('dashboard.fields.breakdown'), value: data.vehicles_breakdown },
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
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.maintenance')}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: t('dashboard.fields.upcoming'), value: data.maintenance_upcoming },
              { label: t('dashboard.fields.dueSoon'), value: data.maintenance_due_soon },
              { label: t('dashboard.fields.due'), value: data.maintenance_due },
              { label: t('dashboard.fields.overdue'), value: data.maintenance_overdue },
              { label: t('dashboard.fields.openRequests'), value: data.maintenance_requests_open },
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
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.workOrders')}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: t('common.fields.active'), value: data.work_orders_active },
              { label: t('dashboard.fields.pendingQc'), value: data.work_orders_pending_qc },
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
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.workshopOperations')}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: t('dashboard.fields.workspacesAvailable'), value: data.workspaces_available },
              { label: t('dashboard.fields.workspacesOccupied'), value: data.workspaces_occupied },
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
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.supplyChainInventory')}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: t('dashboard.fields.totalInventoryValue'), value: formatMoney(data.inventory_total_value) },
              { label: t('dashboard.fields.reservedStock'), value: data.inventory_reserved_stock },
              { label: t('dashboard.fields.lowStock'), value: data.inventory_low_stock },
              { label: t('dashboard.fields.outOfStock'), value: data.inventory_out_of_stock },
              { label: t('dashboard.fields.openTransfers'), value: data.transfers_open },
              { label: t('dashboard.fields.inTransitTransfers'), value: data.transfers_in_transit },
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
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.supplyChainProcurement')}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              { label: t('dashboard.fields.openPurchaseRequests'), value: data.purchase_requests_open },
              { label: t('dashboard.fields.openPurchaseOrders'), value: data.purchase_orders_open },
              { label: t('dashboard.fields.pendingGoodsReceipt'), value: data.goods_receipts_pending },
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
          <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.assetLifecycle')}</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
            {[
              data.active_modules.includes('TIRE') && { label: t('dashboard.fields.tiresInUse'), value: data.tires_in_use },
              data.active_modules.includes('TIRE') && { label: t('dashboard.fields.tiresDueReplacement'), value: data.tires_due_replacement },
              data.active_modules.includes('COMPONENT') && { label: t('dashboard.fields.componentAssetsInstalled'), value: data.component_assets_installed },
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

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.activeModules')}</h2>
      <div className="card" style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        {data.active_modules.map((m) => (
          <span key={m} style={{ background: '#eff6ff', color: '#1d4ed8', padding: '4px 10px', borderRadius: 6, fontSize: 12 }}>
            {m}
          </span>
        ))}
        {data.active_modules.length === 0 && <span style={{ color: '#9ca3af' }}>{t('dashboard.empty.noModulesEntitled')}</span>}
      </div>
    </div>
  );
}
