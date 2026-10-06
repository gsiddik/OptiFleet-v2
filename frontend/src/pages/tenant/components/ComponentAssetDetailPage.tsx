import { useEffect, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { NumericInput } from '../../../components/NumericInput';
import type { ComponentAssetItem, PartnerItem, VehicleItem, Warehouse } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { formatDateTime, formatTimestampDate } from '../../../utils/date';
import { statusLabel } from '../../../i18n/statusRegistry';
import { message } from '../../../i18n/messages';
import { t } from '../../../i18n/i18n';

/** Statuses a Sell Sparepart sale may be raised for (backend ComponentAsset::SELLABLE). */
const SELLABLE = ['SCRAPPED', 'REMOVED'];

export function ComponentAssetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [asset, setAsset] = useState<ComponentAssetItem | null>(null);
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [removalWarehouseId, setRemovalWarehouseId] = useState('');
  const [repairWarehouseId, setRepairWarehouseId] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [vehicleId, setVehicleId] = useState('');
  const [positionLocation, setPositionLocation] = useState('');
  const [removalReason, setRemovalReason] = useState('');
  const [disposition, setDisposition] = useState('REUSE');
  const [repairDescription, setRepairDescription] = useState('');
  const [repairOutcome, setRepairOutcome] = useState('RETURNED_TO_SERVICE');

  function load() {
    apiClient.get(`/app/component-assets/${id}`).then((res) => setAsset(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data)).catch(() => setVehicles([]));
    apiClient.get('/app/warehouses', { params: { status: 'ACTIVE', per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
  }, []);

  async function install() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/install`, { vehicle_id: vehicleId, position_location: positionLocation || undefined });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/remove`, { removal_reason: removalReason, disposition, warehouse_id: removalWarehouseId || undefined });
      setRemovalReason('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function startRepair() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/repairs`, { description: repairDescription });
      setRepairDescription('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function completeRepair(repairId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/repairs/${repairId}/complete`, { outcome: repairOutcome, warehouse_id: repairWarehouseId || undefined });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  useBreadcrumbLabel(asset?.id, asset?.asset_number ?? asset?.serial_number);

  if (error && !asset) return <ErrorState message={error} />;
  if (!asset) return <LoadingState />;

  const canInstall = hasPermission('component_asset.install');
  const canRemove = hasPermission('component_asset.remove');
  const canManage = hasPermission('component_asset.manage');
  const openRepair = (asset.repairs ?? []).find((r) => !r.completed_at);
  const groups = asset.product?.component_groups?.length ? asset.product.component_groups.map(componentGroupLabel).join(', ') : asset.component_group ? componentGroupLabel(asset.component_group) : '—';
  const warehouseSelect = (value: string, onChange: (v: string) => void, label: string) => (
    <FormField label={label}>
      <select aria-label={label} value={value} onChange={(e) => onChange(e.target.value)} style={{ ...inputStyle, width: 220 }}>
        <option value="">{t('common.fields.select')}</option>
        {warehouses.map((w) => (
          <option key={w.id} value={w.id}>
            {w.name}
          </option>
        ))}
      </select>
    </FormField>
  );

  return (
    <div>
      <BackButton fallbackTo="/app/component-assets" label={t('tenantComponents.actions.backToComponentAssets')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0, fontFamily: 'monospace' }}>{asset.asset_number ?? asset.serial_number ?? t('tenantComponents.titles.componentAsset')}</h1>
        <StatusBadge status={asset.current_status} />
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }} data-asset-identity-card>
        <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 220px), 1fr))', gap: '6px 16px', fontSize: 13, margin: 0 }}>
          <Fact label={t('tenantComponents.fields.assetNumber')} value={asset.asset_number} />
          <Fact label={t('common.fields.serialNumber')} value={asset.serial_number} />
          <Fact label={t('common.fields.product')} value={asset.product?.name} />
          <Fact label={t('common.fields.componentGroup')} value={groups} />
          <Fact
            label={t('tenantComponents.fields.locationVehicle')}
            value={
              asset.location ? (
                asset.location.type === 'VEHICLE' ? (
                  <Link to={`/app/vehicles/${asset.location.id}`}>{asset.location.label}</Link>
                ) : (
                  asset.location.label
                )
              ) : null
            }
          />
          <Fact
            label={t('common.fields.source')}
            value={
              asset.source ? (
                <>
                  {t('breadcrumb.goodsReceipts')} {asset.source.gr_number}
                  {asset.source.purchase_order_id && (
                    <>
                      {' '}
                      · <Link to={`/app/purchase-orders/${asset.source.purchase_order_id}`}>{asset.source.po_number}</Link>
                    </>
                  )}
                </>
              ) : null
            }
          />
          {asset.purchase_return && <Fact label={t('tenantComponents.fields.returnedToVendor')} value={t('tenantComponents.fields.returnOrderReturnNumberValue', { return_number: asset.purchase_return.return_number, value: statusLabel(asset.purchase_return.status) })} />}
          {asset.sale && <Fact label={t('common.fields.sale')} value={message('tenantComponents.fields.saleSummary', { status: statusLabel(asset.sale.status), buyerName: asset.sale.buyer_name ?? message('common.fields.partner'), decidedAt: formatDateTime(asset.sale.decided_at) })} />}
        </dl>
      </div>

      {['IN_STOCK', 'REMOVED', 'RECONDITIONED'].includes(asset.current_status) && canInstall && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.actions.install')}</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label={t('common.fields.vehicle')} required>
              <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                <option value="">{t('common.fields.select')}</option>
                {vehicles.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.registration_number}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label={t('tenantComponents.fields.positionLocation')}>
              <input value={positionLocation} onChange={(e) => setPositionLocation(e.target.value)} style={{ ...inputStyle, width: 200 }} />
            </FormField>
            <button className="btn-primary" disabled={busy || !vehicleId} onClick={install} style={{ marginBottom: 14 }}>
              {t('tenantComponents.actions.install')}
            </button>
          </div>
        </div>
      )}

      {['INSTALLED', 'ACTIVE', 'FAILED'].includes(asset.current_status) && canRemove && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.removeReplace')}</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label={t('tenantComponents.fields.removalReason')} required>
              <input value={removalReason} onChange={(e) => setRemovalReason(e.target.value)} style={{ ...inputStyle, width: 220 }} />
            </FormField>
            <FormField label={t('tenantComponents.fields.disposition')} required>
              <select value={disposition} onChange={(e) => setDisposition(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                {['REUSE', 'REPAIR', 'SCRAP'].map((d) => (
                  <option key={d} value={d}>
                    {d}
                  </option>
                ))}
              </select>
            </FormField>
            {warehouseSelect(removalWarehouseId, setRemovalWarehouseId, t('tenantComponents.fields.storeInWarehouse'))}
            <button className="btn-secondary" disabled={busy || !removalReason} onClick={remove} style={{ marginBottom: 14 }}>
              {t('tenantComponents.actions.removeFromVehicle')}
            </button>
          </div>
        </div>
      )}

      {asset.current_status === 'UNDER_REPAIR' && canManage && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.repair')}</h3>
          {!openRepair ? (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <FormField label={t('common.fields.description')} required>
                <input value={repairDescription} onChange={(e) => setRepairDescription(e.target.value)} style={{ ...inputStyle, width: 260 }} />
              </FormField>
              <button className="btn-secondary" disabled={busy || !repairDescription} onClick={startRepair} style={{ marginBottom: 14 }}>
                {t('tenantComponents.actions.startRepair')}
              </button>
            </div>
          ) : (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <FormField label={t('tenantComponents.fields.outcome')} required>
                <select value={repairOutcome} onChange={(e) => setRepairOutcome(e.target.value)} style={{ ...inputStyle, width: 200 }}>
                  {['RECONDITIONED', 'SCRAPPED', 'RETURNED_TO_SERVICE'].map((o) => (
                    <option key={o} value={o}>
                      {o}
                    </option>
                  ))}
                </select>
              </FormField>
              {repairOutcome !== 'SCRAPPED' && !asset.current_warehouse_id && warehouseSelect(repairWarehouseId, setRepairWarehouseId, t('tenantComponents.fields.backInWarehouse'))}
              <button className="btn-primary" disabled={busy} onClick={() => completeRepair(openRepair.id)} style={{ marginBottom: 14 }}>
                {t('tenantComponents.actions.completeRepair')}
              </button>
            </div>
          )}
        </div>
      )}

      {SELLABLE.includes(asset.current_status) && hasPermission('sparepart_sale.create') && <SellAssetCard asset={asset} onSold={load} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.installationHistory')}</h3>
        {(asset.installations ?? []).length === 0 && <EmptyState label={t('tenantComponents.empty.noInstallationsYet')} />}
        {(asset.installations ?? []).map((i) => (
          <div key={i.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {t('tenantComponents.help.installationLine', { vehicle: i.vehicle?.registration_number ?? i.vehicle_id, position: i.position_location ?? '—', date: formatTimestampDate(i.installed_at) })}
            {i.removed_at && ` — removed ${formatTimestampDate(i.removed_at)}`}
          </div>
        ))}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.removalRepairHistory')}</h3>
        {(asset.removals ?? []).length === 0 && <EmptyState label={t('tenantComponents.empty.noRemovalsYet')} />}
        {(asset.removals ?? []).map((r) => (
          <div key={r.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {formatTimestampDate(r.removed_at)} — {r.removal_reason} — {r.disposition}
          </div>
        ))}
      </div>

      <div className="card" style={{ marginTop: 16 }} data-status-history>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.statusHistory')}</h3>
        {(asset.status_history ?? []).length === 0 && <EmptyState label={t('tenantComponents.empty.noStatusChangesRecorded')} />}
        {(asset.status_history ?? []).map((h, i) => (
          <div key={i} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {formatDateTime(h.at)} — {h.action === 'created' ? t('tenantComponents.fields.registered') : `${h.from ? statusLabel(h.from) : '—'} → ${h.to ? statusLabel(h.to) : '—'}`}
          </div>
        ))}
      </div>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt style={{ color: '#6b7280', fontSize: 12 }}>{label}</dt>
      <dd style={{ margin: 0 }}>{value ?? '—'}</dd>
    </div>
  );
}

/**
 * Sell a SCRAPPED or REMOVED asset through Sell Sparepart: creates a DRAFT sale (one per asset);
 * it is submitted and approved in Inventory → Sell Sparepart, and approval marks the asset SOLD.
 */
function SellAssetCard({ asset, onSold }: { asset: ComponentAssetItem; onSold: () => void }) {
  const scrapped = asset.current_status === 'SCRAPPED';
  const [saleType, setSaleType] = useState(scrapped ? 'SCRAP_MATERIAL' : 'OPERATIONAL_REUSE');
  const [buyerType, setBuyerType] = useState('EXTERNAL');
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [partnerId, setPartnerId] = useState('');
  const [buyerName, setBuyerName] = useState('');
  const [unitPrice, setUnitPrice] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/partners', { params: { per_page: 100 } }).then((res) => setPartners(res.data.data)).catch(() => setPartners([]));
  }, []);

  async function sell() {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.post('/app/sparepart-sales/component-assets', {
        component_asset_ids: [asset.id],
        sale_type: saleType,
        buyer_type: buyerType,
        partner_id: buyerType === 'PARTNER' ? partnerId || undefined : undefined,
        buyer_name: buyerType === 'EXTERNAL' ? buyerName || undefined : undefined,
        unit_price: unitPrice,
      });
      setCreated(res.data.data[0]?.id ?? null);
      onSold();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card" style={{ marginBottom: 16 }} data-sell-asset>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.sell')}</h3>
      {created ? (
        <p style={{ fontSize: 13, margin: 0 }}>
          {t('tenantComponents.help.draftSaleCreatedSubmitApproval')} <Link to="/app/sparepart-sales">{t('tenantComponents.actions.inventorySellSparepart')}</Link>{t('tenantComponents.help.approvalMarksAssetSold')}
        </p>
      ) : (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <FormField label={t('tenantComponents.fields.saleType')} required>
            <select aria-label={t('tenantComponents.fields.saleType')} value={saleType} disabled={scrapped} onChange={(e) => setSaleType(e.target.value)} style={{ ...inputStyle, width: 180 }}>
              <option value="OPERATIONAL_REUSE">{t('tenantComponents.fields.operationalReuse')}</option>
              <option value="SCRAP_MATERIAL">{t('tenantComponents.fields.scrapMaterial')}</option>
            </select>
          </FormField>
          <FormField label={t('tenantComponents.fields.buyer')} required>
            <select aria-label={t('tenantComponents.fields.buyerType')} value={buyerType} onChange={(e) => setBuyerType(e.target.value)} style={{ ...inputStyle, width: 140 }}>
              <option value="EXTERNAL">{t('tenantComponents.fields.external')}</option>
              <option value="PARTNER">{t('common.fields.partner')}</option>
            </select>
          </FormField>
          {buyerType === 'PARTNER' ? (
            <FormField label={t('common.fields.partner')} required>
              <select aria-label={t('common.fields.partner')} value={partnerId} onChange={(e) => setPartnerId(e.target.value)} style={{ ...inputStyle, width: 200 }}>
                <option value="">{t('common.fields.select')}</option>
                {partners.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </FormField>
          ) : (
            <FormField label={t('tenantComponents.fields.buyerName')} required>
              <input aria-label={t('tenantComponents.fields.buyerName')} value={buyerName} onChange={(e) => setBuyerName(e.target.value)} style={{ ...inputStyle, width: 200 }} />
            </FormField>
          )}
          <FormField label={t('common.fields.unitPrice')} required>
            <NumericInput aria-label={t('common.fields.unitPrice')} value={unitPrice} onChange={(e) => setUnitPrice(e.target.value)} style={{ ...inputStyle, width: 150 }} />
          </FormField>
          <button className="btn-primary" disabled={busy || !unitPrice || (buyerType === 'PARTNER' ? !partnerId : !buyerName.trim())} onClick={sell} style={{ marginBottom: 14 }}>
            {busy ? t('common.actions.saving') : t('tenantComponents.actions.createSale')}
          </button>
        </div>
      )}
      {error && (
        <div role="alert" style={{ color: '#b91c1c', fontSize: 13 }}>
          {error}
        </div>
      )}
    </div>
  );
}
