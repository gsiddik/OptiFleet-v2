import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { NumericInput } from '../../../components/NumericInput';
import type { Warehouse } from '../../../types';

/**
 * Used Tire Management → Removed → Inspect & return to stock. A removed tire is inspected before it
 * can be reused: PASS returns it to stock as a Used tire in the chosen warehouse; FAIL sends it to
 * retread, repair or scrap. The backend validates and applies the outcome.
 */
export function RemovedTireInspectionSection({ tireId, onDone }: { tireId: string; onDone: () => void }) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [tread, setTread] = useState('');
  const [condition, setCondition] = useState('');
  const [result, setResult] = useState<'PASS' | 'FAIL'>('PASS');
  const [warehouseId, setWarehouseId] = useState('');
  const [failDisposition, setFailDisposition] = useState('RETREAD');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    apiClient
      .get('/app/warehouses', { params: { status: 'ACTIVE', per_page: 100 } })
      .then((res) => setWarehouses(res.data.data))
      .catch(() => setWarehouses([]));
  }, []);

  async function submit() {
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      await apiClient.post(`/app/tires/${tireId}/inspect-removed`, {
        tread_depth_mm: tread,
        condition: condition || undefined,
        result,
        warehouse_id: result === 'PASS' ? warehouseId : undefined,
        fail_disposition: result === 'FAIL' ? failDisposition : undefined,
        notes: notes || undefined,
      });
      onDone();
    } catch (e) {
      const api: ApiErrorShape = extractApiError(e);
      setErrors(api.errors ?? {});
      if (!api.errors) setError(api.message);
    } finally {
      setBusy(false);
    }
  }

  const ready = tread.trim() !== '' && (result === 'FAIL' || warehouseId !== '');

  return (
    <div className="card" id="used-inspection" data-removed-inspection style={{ marginBottom: 16 }}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Inspect &amp; return to stock</h3>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>
        This tire was removed from a vehicle. Inspect it before reuse: <strong>Pass</strong> returns it to stock as a Used tire; <strong>Fail</strong> sends it to retread, repair or scrap.
      </p>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 8 }}>{error}</div>}
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-start' }}>
        <FormField label="Tread Depth (mm)" errors={errors.tread_depth_mm} required>
          <NumericInput aria-label="Tread depth" step="0.01" value={tread} onChange={(e) => setTread(e.target.value)} style={{ ...inputStyle, width: 140 }} />
        </FormField>
        <FormField label="Condition" errors={errors.condition}>
          <input aria-label="Condition" value={condition} maxLength={100} onChange={(e) => setCondition(e.target.value)} placeholder="e.g. even wear, no cuts" style={{ ...inputStyle, width: 220 }} />
        </FormField>
        <FormField label="Result" errors={errors.result} required>
          <select aria-label="Result" value={result} onChange={(e) => setResult(e.target.value as 'PASS' | 'FAIL')} style={{ ...inputStyle, width: 130 }}>
            <option value="PASS">Pass</option>
            <option value="FAIL">Fail</option>
          </select>
        </FormField>
        {result === 'PASS' ? (
          <FormField label="Return to Warehouse" errors={errors.warehouse_id} required>
            <select aria-label="Return to warehouse" value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
              <option value="">Select…</option>
              {warehouses.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
          </FormField>
        ) : (
          <FormField label="Send To" errors={errors.fail_disposition} required>
            <select aria-label="Send to" value={failDisposition} onChange={(e) => setFailDisposition(e.target.value)} style={{ ...inputStyle, width: 160 }}>
              <option value="RETREAD">Retread</option>
              <option value="REPAIR">Repair</option>
              <option value="SCRAP">Scrap</option>
            </select>
          </FormField>
        )}
        <FormField label="Notes" errors={errors.notes}>
          <input aria-label="Notes" value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, width: 240 }} />
        </FormField>
      </div>
      <button type="button" className="btn-primary" disabled={busy || !ready} onClick={submit} style={{ marginTop: 6 }}>
        {busy ? 'Saving…' : result === 'PASS' ? 'Pass & return to stock' : 'Fail & send'}
      </button>
    </div>
  );
}
