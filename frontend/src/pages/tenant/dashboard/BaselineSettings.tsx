import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { NumericInput } from '../../../components/NumericInput';
import { t } from '../../../i18n/i18n';
import { codeLabel } from './labels';

interface Row { maintenance_type: string; baseline_hours: string | null }

/**
 * Mechanic Performance baselines (expected work hours per maintenance type). Only offered to users
 * the backend reports as allowed (mechanic_baseline.manage); the API enforces it again. An empty
 * field means "not set" — no default is ever assumed.
 */
export function BaselineSettings({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved: () => void }) {
  const [rows, setRows] = useState<Row[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failed, setFailed] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFailed(null);
    apiClient.get('/app/dashboard/mechanic-baselines')
      .then((res) => setRows(res.data.data as Row[]))
      .catch((e) => setFailed(extractApiError(e).message ?? t('dashboard.states.loadFailed')));
  }, [open]);

  async function save() {
    setSaving(true);
    setErrors({});
    try {
      await apiClient.put('/app/dashboard/mechanic-baselines', {
        baselines: rows.map((r) => ({ maintenance_type: r.maintenance_type, baseline_hours: r.baseline_hours === '' ? null : r.baseline_hours })),
      });
      onSaved();
      onClose();
    } catch (e) {
      const err = extractApiError(e);
      setErrors(err.errors ?? {});
      setFailed(err.message ?? null);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open={open} title={t('dashboard.baselines.title')} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('dashboard.baselines.help')}</p>
      {failed && <p role="alert" style={{ fontSize: 12, color: '#b91c1c' }}>{failed}</p>}
      {rows.map((r, i) => (
        <FormField key={r.maintenance_type} label={t('dashboard.baselines.hoursFor', { type: codeLabel(r.maintenance_type, 'type') })}
          errors={errors[`baselines.${i}.baseline_hours`]}>
          <NumericInput step="0.25" min="0" value={r.baseline_hours ?? ''} placeholder={t('dashboard.baselines.notSet')}
            onChange={(e) => setRows((prev) => prev.map((x, j) => (j === i ? { ...x, baseline_hours: e.target.value } : x)))} style={inputStyle} />
        </FormField>
      ))}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button type="button" className="btn-secondary" onClick={onClose}>{t('common.actions.cancel')}</button>
        <button type="button" className="btn-primary" disabled={saving || rows.length === 0} onClick={save}>{t('common.actions.save')}</button>
      </div>
    </Modal>
  );
}
