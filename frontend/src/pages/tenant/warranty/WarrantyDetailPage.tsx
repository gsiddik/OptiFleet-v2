import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { WarrantyItem } from '../../../types';

export function WarrantyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [warranty, setWarranty] = useState<WarrantyItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [currentOdometer, setCurrentOdometer] = useState('');
  const [eligibility, setEligibility] = useState<string | null>(null);

  function load() {
    apiClient.get(`/app/warranties/${id}`).then((res) => setWarranty(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function checkEligibility() {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.post(`/app/warranties/${id}/eligibility`, { current_odometer: currentOdometer || undefined });
      setEligibility(res.data.data.status);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function voidWarranty() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/warranties/${id}/void`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !warranty) return <ErrorState message={error} />;
  if (!warranty) return <LoadingState />;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{warranty.coverage_basis} Warranty</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={warranty.status} />
          {warranty.status === 'ACTIVE' && hasPermission('warranty.manage') && (
            <button className="btn-secondary" disabled={busy} onClick={voidWarranty}>
              Void
            </button>
          )}
        </div>
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>Product:</strong> {warranty.product?.name ?? '—'} &nbsp; <strong>Vendor:</strong> {warranty.partner?.name ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Starts:</strong> {warranty.starts_at} &nbsp; <strong>Duration:</strong> {warranty.duration_months ?? '—'} months /{' '}
          {warranty.duration_km ?? '—'} km / {warranty.duration_engine_hours ?? '—'} engine hrs
        </p>
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Eligibility Check</h3>
        <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
          <FormField label="Current Odometer (optional)">
            <input type="number" value={currentOdometer} onChange={(e) => setCurrentOdometer(e.target.value)} style={{ ...inputStyle, width: 160 }} />
          </FormField>
          <button className="btn-secondary" disabled={busy} onClick={checkEligibility} style={{ marginBottom: 14 }}>
            Check Eligibility
          </button>
        </div>
        {eligibility && (
          <p style={{ fontSize: 14 }}>
            Result: <StatusBadge status={eligibility} />
          </p>
        )}
      </div>
    </div>
  );
}
