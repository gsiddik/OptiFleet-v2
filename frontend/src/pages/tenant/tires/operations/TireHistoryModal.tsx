import { useEffect, useState, type ReactNode } from 'react';
import { apiClient, extractApiError } from '../../../../api/client';
import { Modal } from '../../../../components/Modal';
import { ErrorState, LoadingState } from '../../../../components/States';
import { StatusBadge } from '../../../../components/StatusBadge';
import { PositionLabel } from '../../../../components/tires/PositionLabel';
import { formatKm } from './tireOperationFormat';
import { t } from '../../../../i18n/i18n';

interface TireHistory {
  tire: { id: string; serial_number: string; current_status: string };
  installations: { id: string; vehicle: { id: string; registration_number: string | null } | null; position_code: string; installed_at: string | null; installation_odometer: string | null; removed_at: string | null; removal_odometer: string | null; removal_reason: string | null; removal_disposition: string | null }[];
  rotations: { id: string; registration_number: string | null; from_position: string; to_position: string; odometer: string | null; occurred_at: string | null }[];
  inspections: { id: string; inspected_at: string | null; tread_depth_mm: string | null; pressure_psi: string | null; condition: string | null; damage: string | null; recommendation: string | null; inspector: string | null }[];
}

/**
 * Tire History of one physical tire: Installation, Rotation and Inspection History from the actual
 * records, oldest first. A section without records is not shown at all.
 */
export function TireHistoryModal({ tireId, serial, onClose }: { tireId: string; serial: string; onClose: () => void }) {
  const [history, setHistory] = useState<TireHistory | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get(`/app/tires/${tireId}/history`)
      .then((res) => setHistory(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [tireId]);

  const empty = history && history.installations.length + history.rotations.length + history.inspections.length === 0;

  return (
    <Modal open title={t('tire.modals.tireHistorySerial', { serial: serial })} onClose={onClose} width={860}>
      {error && <ErrorState message={error} />}
      {!error && !history && <LoadingState />}
      {history && (
        <div data-tire-history style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr)', gap: 16 }}>
          <div style={{ fontSize: 13 }}>
            {t('tire.fields.currentStatus')}: <StatusBadge status={history.tire.current_status} />
          </div>
          {empty && <p style={{ fontSize: 13, color: '#6b7280', margin: 0 }}>{t('tire.empty.noHistoryBeenRecordedTireYet')}</p>}
          {history.installations.length > 0 && (
            <HistorySection title={t('tenantComponents.sections.installationHistory')} kind="installations" headers={[t('common.fields.vehicle'), t('inventory.placeholders.position'), t('analytics.fields.installed'), t('tire.fields.installKm'), t('tire.fields.removed'), t('tire.fields.removalKm'), t('tenantComponents.fields.removalReason')]}>
              {history.installations.map((i) => (
                <tr key={i.id}>
                  <Td>{i.vehicle?.registration_number ?? '—'}</Td>
                  <Td>
                    <PositionLabel code={i.position_code} />
                  </Td>
                  <Td>{i.installed_at ?? '—'}</Td>
                  <Td>{formatKm(i.installation_odometer)}</Td>
                  <Td>{i.removed_at ?? t('tire.fields.stillInstalled')}</Td>
                  <Td>{formatKm(i.removal_odometer)}</Td>
                  <Td>{i.removal_reason ?? '—'}</Td>
                </tr>
              ))}
            </HistorySection>
          )}
          {history.rotations.length > 0 && (
            <HistorySection title={t('tire.sections.rotationHistory')} kind="rotations" headers={[t('common.fields.date'), t('common.fields.vehicle'), t('common.fields.from'), t('common.fields.to'), 'KM']}>
              {history.rotations.map((r) => (
                <tr key={r.id}>
                  <Td>{r.occurred_at ?? '—'}</Td>
                  <Td>{r.registration_number ?? '—'}</Td>
                  <Td>
                    <PositionLabel code={r.from_position} />
                  </Td>
                  <Td>
                    <PositionLabel code={r.to_position} />
                  </Td>
                  <Td>{formatKm(r.odometer)}</Td>
                </tr>
              ))}
            </HistorySection>
          )}
          {history.inspections.length > 0 && (
            <HistorySection title={t('tire.sections.inspectionHistory')} kind="inspections" headers={[t('common.fields.date'), t('tire.fields.treadDepth'), t('common.fields.condition'), t('tire.fields.damageNotes'), t('tire.fields.inspector')]}>
              {history.inspections.map((i) => (
                <tr key={i.id}>
                  <Td>{i.inspected_at ?? '—'}</Td>
                  <Td>{i.tread_depth_mm != null ? t('tire.help.dPullMmMm', { d_pull_mm: i.tread_depth_mm }) : '—'}</Td>
                  <Td>{i.condition ?? '—'}</Td>
                  <Td>{[i.damage, i.recommendation].filter(Boolean).join(' · ') || '—'}</Td>
                  <Td>{i.inspector ?? '—'}</Td>
                </tr>
              ))}
            </HistorySection>
          )}
        </div>
      )}
    </Modal>
  );
}

function HistorySection({ title, kind, headers, children }: { title: string; kind: string; headers: string[]; children: ReactNode }) {
  return (
    <section data-history-section={kind} style={{ minWidth: 0 }}>
      <h4 style={{ margin: '0 0 6px', fontSize: 14 }}>{title}</h4>
      <div style={{ overflowX: 'auto', border: '1px solid #e5e7eb', borderRadius: 6 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr>
              {headers.map((h) => (
                <th key={h} style={{ textAlign: 'left', padding: '6px 10px', background: '#f9fafb', borderBottom: '1px solid #e5e7eb', whiteSpace: 'nowrap', fontSize: 12 }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>{children}</tbody>
        </table>
      </div>
    </section>
  );
}

function Td({ children }: { children: ReactNode }) {
  return <td style={{ padding: '6px 10px', borderBottom: '1px solid #f3f4f6', whiteSpace: 'nowrap' }}>{children}</td>;
}
