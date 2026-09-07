import { useState, type ReactNode } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ConfigurationSetItem, ConfigurationType, ConfigurationVersionItem } from '../../../types';

const textareaStyle = { ...inputStyle, fontFamily: 'ui-monospace, monospace', fontSize: 13, minHeight: 220, resize: 'vertical' as const };

/**
 * Phase 5 Section 40-42: one generic list/draft/publish/archive manager
 * over ConfigurationSet+Version, reused by Numbering/Template/Workflow
 * (each of which is exactly this same DRAFT->PUBLISHED->ARCHIVED shape —
 * see ConfigurationController on the backend). Type-specific pickers
 * (numbering tokens, template variables, workflow actions/operators) are
 * injected via `payloadHelp` / `renderExtraActions` rather than
 * duplicating this list/editor/history shell three times.
 */
export function ConfigurationSetManager({
  type,
  manageParm,
  publishPermission,
  codeLabel,
  codePlaceholder,
  payloadHelp,
  renderExtraActions,
  onPreview,
}: {
  type: ConfigurationType;
  manageParm: string;
  publishPermission: string;
  codeLabel: string;
  codePlaceholder: string;
  payloadHelp?: ReactNode;
  renderExtraActions?: (set: ConfigurationSetItem, version: ConfigurationVersionItem) => ReactNode;
  onPreview?: (set: ConfigurationSetItem, payload: Record<string, unknown>) => void;
}) {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editingVersion, setEditingVersion] = useState<{ set: ConfigurationSetItem; version: ConfigurationVersionItem } | null>(null);
  const { data, loading, error } = useApiList<ConfigurationSetItem>('/app/configuration/sets', { type }, reloadKey);
  const canManage = hasPermission(manageParm);
  const canPublish = hasPermission(publishPermission);

  function reload() {
    setReloadKey((k) => k + 1);
  }

  async function publish(versionId: string) {
    try {
      await apiClient.post(`/app/configuration/versions/${versionId}/publish`);
      reload();
    } catch (err) {
      alert(extractApiError(err).message);
    }
  }

  async function archive(versionId: string) {
    try {
      await apiClient.post(`/app/configuration/versions/${versionId}/archive`);
      reload();
    } catch (err) {
      alert(extractApiError(err).message);
    }
  }

  return (
    <div>
      <Toolbar
        actions={
          canManage ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New {codeLabel}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={`No ${codeLabel.toLowerCase()} configurations yet.`} />}
      {!error &&
        !loading &&
        data.map((set) => (
          <div key={set.id} style={{ border: '1px solid #e5e7eb', borderRadius: 8, marginBottom: 14, overflow: 'hidden' }}>
            <div style={{ background: '#f9fafb', padding: '10px 14px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <strong>{set.name}</strong> <span style={{ color: '#6b7280', fontSize: 13 }}>({set.code})</span>
                {set.is_system && <span style={{ marginLeft: 8, fontSize: 11, color: '#a16207' }}>PLATFORM DEFAULT</span>}
              </div>
              {canManage && (
                <button
                  className="btn-secondary"
                  onClick={() =>
                    setEditingVersion({
                      set,
                      version: {
                        id: '',
                        configuration_set_id: set.id,
                        version_number: 0,
                        status: 'DRAFT',
                        payload: set.versions[0]?.payload ?? {},
                        change_summary: null,
                        created_by: null,
                        published_by: null,
                        published_at: null,
                        archived_at: null,
                      },
                    })
                  }
                >
                  + New Draft
                </button>
              )}
            </div>
            <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
              <tbody>
                {set.versions.map((v) => (
                  <tr key={v.id} style={{ borderTop: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '8px 14px', width: 80 }}>v{v.version_number}</td>
                    <td style={{ padding: '8px 14px', width: 110 }}>
                      <StatusBadge status={v.status} />
                    </td>
                    <td style={{ padding: '8px 14px', color: '#6b7280' }}>{v.change_summary ?? '—'}</td>
                    <td style={{ padding: '8px 14px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                      {v.status === 'DRAFT' && canManage && (
                        <button className="btn-secondary" style={{ marginRight: 6 }} onClick={() => setEditingVersion({ set, version: v })}>
                          Edit
                        </button>
                      )}
                      {v.status === 'DRAFT' && canPublish && (
                        <button className="btn-primary" style={{ marginRight: 6 }} onClick={() => publish(v.id)}>
                          Publish
                        </button>
                      )}
                      {v.status === 'PUBLISHED' && canManage && (
                        <button className="btn-secondary" style={{ marginRight: 6 }} onClick={() => archive(v.id)}>
                          Archive
                        </button>
                      )}
                      {onPreview && <button className="btn-secondary" onClick={() => onPreview(set, v.payload)}>Preview</button>}
                      {renderExtraActions?.(set, v)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      <DraftEditorModal
        open={showCreate}
        title={`New ${codeLabel} Configuration`}
        codeLabel={codeLabel}
        codePlaceholder={codePlaceholder}
        payloadHelp={payloadHelp}
        onClose={() => setShowCreate(false)}
        onSubmit={async ({ code, name, payload }) => {
          await apiClient.post('/app/configuration/versions', { type, code, name, payload: JSON.parse(payload) });
          setShowCreate(false);
          reload();
        }}
      />

      {editingVersion && (
        <DraftEditorModal
          open
          title={editingVersion.version.id ? `Edit Draft — ${editingVersion.set.code}` : `New Draft — ${editingVersion.set.code}`}
          codeLabel={codeLabel}
          codePlaceholder={codePlaceholder}
          fixedCode={editingVersion.set.code}
          fixedName={editingVersion.set.name}
          initialPayload={JSON.stringify(editingVersion.version.payload, null, 2)}
          payloadHelp={payloadHelp}
          onClose={() => setEditingVersion(null)}
          onSubmit={async ({ payload, changeSummary }) => {
            if (editingVersion.version.id) {
              await apiClient.put(`/app/configuration/versions/${editingVersion.version.id}`, { payload: JSON.parse(payload), change_summary: changeSummary });
            } else {
              await apiClient.post('/app/configuration/versions', {
                type, code: editingVersion.set.code, name: editingVersion.set.name, payload: JSON.parse(payload), change_summary: changeSummary,
              });
            }
            setEditingVersion(null);
            reload();
          }}
        />
      )}
    </div>
  );
}

function DraftEditorModal({
  open,
  title,
  codeLabel,
  codePlaceholder,
  fixedCode,
  fixedName,
  initialPayload,
  payloadHelp,
  onClose,
  onSubmit,
}: {
  open: boolean;
  title: string;
  codeLabel: string;
  codePlaceholder: string;
  fixedCode?: string;
  fixedName?: string;
  initialPayload?: string;
  payloadHelp?: ReactNode;
  onClose: () => void;
  onSubmit: (args: { code: string; name: string; payload: string; changeSummary: string }) => Promise<void>;
}) {
  const [code, setCode] = useState(fixedCode ?? '');
  const [name, setName] = useState(fixedName ?? '');
  const [payload, setPayload] = useState(initialPayload ?? '{\n  \n}');
  const [changeSummary, setChangeSummary] = useState('');
  const [jsonError, setJsonError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  if (!open) return null;

  async function submit() {
    setJsonError(null);
    setErrors({});
    try {
      JSON.parse(payload);
    } catch {
      setJsonError('Payload is not valid JSON.');
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit({ code, name, payload, changeSummary });
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      setJsonError(apiError.errors ? null : apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title={title} onClose={onClose} width={620}>
      {!fixedCode && (
        <FormField label={codeLabel} errors={errors.code}>
          <input value={code} onChange={(e) => setCode(e.target.value)} placeholder={codePlaceholder} style={inputStyle} />
        </FormField>
      )}
      {!fixedName && (
        <FormField label="Name" errors={errors.name}>
          <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
        </FormField>
      )}
      <FormField label="Change Summary" errors={errors.change_summary}>
        <input value={changeSummary} onChange={(e) => setChangeSummary(e.target.value)} placeholder="What changed and why" style={inputStyle} />
      </FormField>
      <FormField label="Payload (JSON)" errors={jsonError ? [jsonError] : errors.payload}>
        {payloadHelp}
        <textarea value={payload} onChange={(e) => setPayload(e.target.value)} style={textareaStyle} spellCheck={false} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || (!fixedCode && !code) || (!fixedName && !name)} onClick={submit}>
          Save Draft
        </button>
      </div>
    </Modal>
  );
}
