import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { NotificationEventInfo, NotificationRuleItem } from '../../../types';

const CHANNELS = ['IN_APP', 'EMAIL'];
const RECIPIENT_TYPES = [
  'EXPLICIT_USER', 'PERMISSION', 'ROLE', 'BRANCH_MANAGER', 'WORKSHOP_MANAGER',
  'REQUESTER', 'APPROVER', 'ASSIGNED_MECHANIC', 'VEHICLE_PIC', 'WAREHOUSE_PIC', 'VENDOR_CONTACT', 'CUSTOM_EMAIL',
];

const jsonAreaStyle = { ...inputStyle, fontFamily: 'ui-monospace, monospace', fontSize: 13, minHeight: 90 };

/**
 * Section 40: event / channel / recipient / condition / escalation editor
 * for NotificationRule (a plain, always-current row — not versioned, see
 * NotificationRuleController). Message content (subject/body per channel)
 * is a separate, versioned NOTIFICATION-type configuration edited on the
 * Document Templates page's same generic editor.
 */
export function NotificationRulesPage() {
  const { hasPermission } = useAuth();
  const [events, setEvents] = useState<NotificationEventInfo[]>([]);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<NotificationRuleItem>('/app/notification-rules', {}, reloadKey);
  const canManage = hasPermission('notification_rule.manage');

  useEffect(() => {
    apiClient.get('/app/notification-rules/events').then((res) => setEvents(res.data.data));
  }, []);

  function reload() {
    setReloadKey((k) => k + 1);
  }

  async function toggleActive(rule: NotificationRuleItem) {
    try {
      await apiClient.post(`/app/notification-rules/${rule.id}/${rule.is_active ? 'deactivate' : 'activate'}`);
      reload();
    } catch (err) {
      alert(extractApiError(err).message);
    }
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Notification Rules</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginBottom: 16 }}>
        Wire a domain event to recipients, channels, an optional condition, and an optional escalation. Platform-controlled events
        (subscription/invoice/payment lifecycle) are read-only here.
      </p>
      <Toolbar
        actions={
          canManage ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Rule
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No notification rules configured." />}
      {!error && !loading && data.length > 0 && (
        <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse', border: '1px solid #e5e7eb', borderRadius: 8 }}>
          <thead>
            <tr style={{ background: '#f9fafb', textAlign: 'left' }}>
              <th style={{ padding: 10 }}>Event</th>
              <th style={{ padding: 10 }}>Name</th>
              <th style={{ padding: 10 }}>Channels</th>
              <th style={{ padding: 10 }}>Escalation</th>
              <th style={{ padding: 10 }}>Status</th>
              <th style={{ padding: 10 }} />
            </tr>
          </thead>
          <tbody>
            {data.map((rule) => (
              <tr key={rule.id} style={{ borderTop: '1px solid #f1f5f9' }}>
                <td style={{ padding: 10 }}>{rule.event_code}</td>
                <td style={{ padding: 10 }}>
                  {rule.name}
                  {rule.is_system && <span style={{ marginLeft: 6, fontSize: 11, color: '#a16207' }}>PLATFORM</span>}
                </td>
                <td style={{ padding: 10 }}>{rule.channels.join(', ')}</td>
                <td style={{ padding: 10 }}>{rule.escalation ? `after ${String((rule.escalation as { after_minutes?: number }).after_minutes)}min` : '—'}</td>
                <td style={{ padding: 10 }}>
                  <StatusBadge status={rule.is_active ? 'ACTIVE' : 'INACTIVE'} />
                </td>
                <td style={{ padding: 10, textAlign: 'right' }}>
                  {canManage && !rule.is_system && (
                    <button className="btn-secondary" onClick={() => toggleActive(rule)}>
                      {rule.is_active ? 'Deactivate' : 'Activate'}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <CreateRuleModal open={showCreate} events={events} onClose={() => setShowCreate(false)} onCreated={reload} />
    </div>
  );
}

function CreateRuleModal({ open, events, onClose, onCreated }: { open: boolean; events: NotificationEventInfo[]; onClose: () => void; onCreated: () => void }) {
  const configurableEvents = events.filter((e) => !e.platform_locked);
  const [eventCode, setEventCode] = useState('');
  const [name, setName] = useState('');
  const [recipientRules, setRecipientRules] = useState('[{"type": "ROLE", "identifier": "Fleet Manager"}]');
  const [channels, setChannels] = useState<string[]>(['IN_APP']);
  const [conditionSet, setConditionSet] = useState('');
  const [escalation, setEscalation] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [jsonError, setJsonError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (configurableEvents.length > 0 && !eventCode) setEventCode(configurableEvents[0].code);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [configurableEvents]);

  async function submit() {
    setJsonError(null);
    setErrors({});
    let recipients, condition, esc;
    try {
      recipients = JSON.parse(recipientRules);
      condition = conditionSet ? JSON.parse(conditionSet) : null;
      esc = escalation ? JSON.parse(escalation) : null;
    } catch {
      setJsonError('One of the JSON fields is invalid.');
      return;
    }
    setSubmitting(true);
    try {
      await apiClient.post('/app/notification-rules', {
        event_code: eventCode, name, recipient_rules: recipients, channels, condition_set: condition, escalation: esc,
      });
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      if (!apiError.errors) setJsonError(apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="New Notification Rule" onClose={onClose} width={560}>
      <FormField label="Event" errors={errors.event_code}>
        <select value={eventCode} onChange={(e) => setEventCode(e.target.value)} style={inputStyle}>
          {configurableEvents.map((e) => (
            <option key={e.code} value={e.code}>
              {e.code}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Channels">
        <div style={{ display: 'flex', gap: 12 }}>
          {CHANNELS.map((c) => (
            <label key={c} style={{ fontSize: 13 }}>
              <input
                type="checkbox"
                checked={channels.includes(c)}
                onChange={(e) => setChannels(e.target.checked ? [...channels, c] : channels.filter((x) => x !== c))}
              />{' '}
              {c}
            </label>
          ))}
        </div>
      </FormField>
      <FormField label={`Recipient rules (JSON array of {type, identifier}) — types: ${RECIPIENT_TYPES.join(', ')}`} errors={errors.recipient_rules}>
        <textarea value={recipientRules} onChange={(e) => setRecipientRules(e.target.value)} style={jsonAreaStyle} spellCheck={false} />
      </FormField>
      <FormField label="Condition set (JSON, optional)">
        <textarea value={conditionSet} onChange={(e) => setConditionSet(e.target.value)} placeholder='{"operator":"AND","rules":[{"field":"severity","op":"=","value":"CRITICAL"}]}' style={jsonAreaStyle} spellCheck={false} />
      </FormField>
      <FormField label="Escalation (JSON, optional)">
        <textarea
          value={escalation}
          onChange={(e) => setEscalation(e.target.value)}
          placeholder='{"after_minutes":120,"recipient_rules":[{"type":"ROLE","identifier":"Fleet Manager"}],"unresolved_condition_set":{"operator":"AND","rules":[{"field":"status","op":"!=","value":"RESOLVED"}]}}'
          style={jsonAreaStyle}
          spellCheck={false}
        />
      </FormField>
      {jsonError && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 8 }}>{jsonError}</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !eventCode || !name} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
