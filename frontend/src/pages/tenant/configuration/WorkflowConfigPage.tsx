import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ConfigurationSetManager } from './ConfigurationSetManager';

const RESOURCE_TYPES = [
  'maintenance_request', 'work_order', 'vehicle_transfer', 'breakdown',
  'stock_transfer', 'purchase_request', 'purchase_order', 'warranty_claim',
];

interface SimulatedTransition {
  action_code: string;
  action_label: string;
  to_status: string;
  requires_approval: boolean;
  approval_rule: { type: string; steps: Array<{ step_number: number; approver_type: string; approver_identifier: string }> } | null;
  automated_actions: unknown[];
}

/**
 * Section 41: a practical (not graphical) Workflow Designer — the
 * status/transition/condition/approval-rule/automated-action editor is
 * JSON, with the action/operator catalog surfaced as reference and a real
 * Simulate action that calls WorkflowEngine::simulate() (never mutates)
 * against a chosen status + sample actor + sample context.
 */
export function WorkflowConfigPage() {
  const [actions, setActions] = useState<string[]>([]);
  const [operators, setOperators] = useState<string[]>([]);
  const [simulateFor, setSimulateFor] = useState<string | null>(null);
  const [fromStatus, setFromStatus] = useState('DRAFT');
  const [contextJson, setContextJson] = useState('{}');
  const [simulation, setSimulation] = useState<SimulatedTransition[] | null>(null);
  const [simError, setSimError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/configuration/metadata', { params: { type: 'WORKFLOW' } }).then((res) => {
      setActions(res.data.data.actions);
      setOperators(res.data.data.operators);
    });
  }, []);

  async function runSimulation() {
    setSimError(null);
    setSimulation(null);
    let context: unknown;
    try {
      context = JSON.parse(contextJson);
    } catch {
      setSimError('Context must be valid JSON.');
      return;
    }
    try {
      const res = await apiClient.post('/app/configuration/preview', { type: 'WORKFLOW', code: simulateFor, from_status: fromStatus, context });
      setSimulation(res.data.data);
    } catch (err) {
      setSimError(extractApiError(err).message);
    }
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Workflow Configuration</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginBottom: 8 }}>
        Configure the status graph, transitions, permission requirements, conditions, and approval rules for each business object.
        Payload shape: <code>{'{"statuses":[{"code":"DRAFT","display_name":"Draft","is_start":true}, ...],"transitions":[{"from_status":"DRAFT","to_status":"SUBMITTED","action_code":"submit","action_label":"Submit","required_permission":"...", "condition_set": null, "approval_rule": null, "automated_actions": []}]}'}</code>
      </p>
      <div style={{ marginBottom: 16, fontSize: 12, color: '#6b7280', background: '#f9fafb', padding: 10, borderRadius: 6 }}>
        <div>
          <strong>Automated actions:</strong> {actions.join(', ')}
        </div>
        <div style={{ marginTop: 4 }}>
          <strong>Condition operators:</strong> {operators.join(', ')}
        </div>
      </div>

      <ConfigurationSetManager
        type="WORKFLOW"
        manageParm="workflow.manage"
        publishPermission="workflow.publish"
        codeLabel="Resource Type"
        codePlaceholder="e.g. maintenance_request, work_order"
        renderExtraActions={(set, version) =>
          version.status === 'PUBLISHED' ? (
            <button className="btn-secondary" style={{ marginLeft: 6 }} onClick={() => setSimulateFor(set.code)}>
              Simulate
            </button>
          ) : null
        }
      />

      {simulateFor && (
        <Modal open title={`Simulate — ${simulateFor}`} onClose={() => setSimulateFor(null)} width={640}>
          <p style={{ fontSize: 12, color: '#6b7280' }}>
            Resource types with a seeded default use e.g. <code>DRAFT</code>, <code>SUBMITTED</code>, <code>UNDER_REVIEW</code>. Never
            mutates any data.
          </p>
          <FormField label="From status">
            <select value={fromStatus} onChange={(e) => setFromStatus(e.target.value)} style={inputStyle}>
              {RESOURCE_TYPES.includes(simulateFor)
                ? ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REQUESTED', 'REPORTED', 'IN_PROGRESS', 'QC_PENDING'].map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))
                : <option value={fromStatus}>{fromStatus}</option>}
            </select>
          </FormField>
          <FormField label="Sample context (JSON)">
            <textarea value={contextJson} onChange={(e) => setContextJson(e.target.value)} style={{ ...inputStyle, fontFamily: 'ui-monospace, monospace', minHeight: 100 }} />
          </FormField>
          <button className="btn-primary" onClick={runSimulation}>
            Run Simulation
          </button>
          {simError && <div style={{ color: '#b91c1c', fontSize: 13, marginTop: 8 }}>{simError}</div>}
          {simulation && (
            <table style={{ width: '100%', fontSize: 13, marginTop: 12, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
                  <th style={{ padding: 6 }}>Action</th>
                  <th style={{ padding: 6 }}>To Status</th>
                  <th style={{ padding: 6 }}>Requires Approval</th>
                </tr>
              </thead>
              <tbody>
                {simulation.length === 0 && (
                  <tr>
                    <td colSpan={3} style={{ padding: 6, color: '#9ca3af' }}>
                      No transitions available from this status for the current user.
                    </td>
                  </tr>
                )}
                {simulation.map((t) => (
                  <tr key={t.action_code} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: 6 }}>{t.action_label}</td>
                    <td style={{ padding: 6 }}>{t.to_status}</td>
                    <td style={{ padding: 6 }}>{t.requires_approval ? `Yes (${t.approval_rule?.type})` : 'No'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Modal>
      )}
    </div>
  );
}
