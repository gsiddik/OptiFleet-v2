import { useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ConfigurationSetManager } from './ConfigurationSetManager';
import type { ConfigurationSetItem } from '../../../types';

interface DryRunResult {
  spa_raw_percent: number | null;
  spa_normalized_score: number | null;
  classification: string | null;
  ka_score: number | null;
  kf_score: number | null;
  critical_safety_fail: boolean | null;
  eligible_for_operational_reuse: boolean | null;
  missing_inputs: string[];
}

interface PreviewResponse {
  draft_result: DryRunResult;
  active_configuration_version_id: string | null;
  active_result: DryRunResult | null;
  changed_from_active: boolean;
  note: string;
}

/**
 * R2 (Production Readiness): configuration UI for the REPAIR/RETREAD
 * TIRE_SCORING scoring bands PLUS the three newly-required disposition
 * policy categories — legal_restrictions, casing_eligibility, and
 * lifecycle_limits — that gate TireService::retread()/repair() via
 * TireDispositionEligibilityService. Deliberately excludes any
 * vehicle-class/axle-position rule or a dedicated Tire-specialist/safety-
 * department approval step — out of scope per this phase's business
 * decision (see IMPROVEMENT_CONTEXT.md). "Dry Run" is a non-mutating
 * preview only — it never changes tire status or finalizes anything.
 */
export function TireScoringConfigPage() {
  const [dryRunFor, setDryRunFor] = useState<{ set: ConfigurationSetItem; payload: Record<string, unknown> } | null>(null);
  const [referenceTread, setReferenceTread] = useState('10');
  const [measuredTread, setMeasuredTread] = useState('6');
  const [kaScore, setKaScore] = useState('');
  const [criticalFail, setCriticalFail] = useState(false);
  const [result, setResult] = useState<PreviewResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [running, setRunning] = useState(false);

  async function runDryRun() {
    if (!dryRunFor) return;
    setError(null);
    setResult(null);
    setRunning(true);
    try {
      const res = await apiClient.post('/app/configuration/preview', {
        type: 'TIRE_SCORING',
        code: dryRunFor.set.code,
        payload: dryRunFor.payload,
        reference_tread_depth_mm: referenceTread === '' ? null : Number(referenceTread),
        measured_tread_depth_mm: measuredTread === '' ? null : Number(measuredTread),
        ka_score: kaScore === '' ? null : Number(kaScore),
        critical_safety_fail: criticalFail,
      });
      setResult(res.data.data);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setRunning(false);
    }
  }

  function closeDryRun() {
    setDryRunFor(null);
    setResult(null);
    setError(null);
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Tire Scoring Configuration</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginBottom: 8 }}>
        Separate REPAIR and RETREAD configurations. Each payload must include <code>bands</code> (SPA classification bands) and the three
        required disposition-policy categories: <code>legal_restrictions</code>, <code>casing_eligibility</code>, and{' '}
        <code>lifecycle_limits</code>. Vehicle-class/axle-position rules and a dedicated Tire-specialist/safety-department approval step
        are explicitly out of scope for this configuration — never required to publish or activate.
      </p>
      <div style={{ marginBottom: 16, fontSize: 12, color: '#6b7280', background: '#f9fafb', padding: 10, borderRadius: 6 }}>
        <div>
          <strong>bands:</strong> array of {'{min_percent, max_percent, classification, normalized_score, eligible_for_operational_reuse, is_critical_fail?}'}
        </div>
        <div style={{ marginTop: 4 }}>
          <strong>legal_restrictions:</strong> array of {'{code, name, description, jurisdiction, restriction_type, effective_date, expiry_date?, behavior: BLOCK|WARN, applicable_process: REPAIR|RETREAD|BOTH}'}
        </div>
        <div style={{ marginTop: 4 }}>
          <strong>casing_eligibility:</strong> {'{require_inspection, prohibited_damage_categories, max_previous_repairs, max_previous_retreads, max_age_months, max_mileage_km, exclude_if_critical_fail, required_measurements}'} — numeric limits may be <code>null</code> (no limit), but every key must be present. Missing tire history data always fails closed (BLOCK).
        </div>
        <div style={{ marginTop: 4 }}>
          <strong>lifecycle_limits:</strong> {'{max_age_months, max_mileage_km, max_repair_count, max_retread_count, missing_data_behavior: BLOCK|WARN, boundary_inclusive}'}
        </div>
        <div style={{ marginTop: 4 }}>
          Publishing (activating) requires a different user than the one who drafted the version (maker-checker) and complete, valid
          values for every required category.
        </div>
      </div>

      <ConfigurationSetManager
        type="TIRE_SCORING"
        manageParm="tire_scoring_configuration.manage"
        publishPermission="tire_scoring_configuration.publish"
        codeLabel="Scoring Type"
        codePlaceholder="REPAIR or RETREAD"
        renderExtraActions={(set, version) => (
          <button className="btn-secondary" style={{ marginLeft: 6 }} onClick={() => setDryRunFor({ set, payload: version.payload })}>
            Dry Run
          </button>
        )}
      />

      {dryRunFor && (
        <Modal open title={`Dry Run — ${dryRunFor.set.code}`} onClose={closeDryRun} width={640}>
          <p style={{ fontSize: 12, color: '#6b7280' }}>
            Non-operational preview only: never changes a tire's status, never finalizes an inspection, and never persists a scoring
            result. If this configuration is currently PUBLISHED and there is also a different ACTIVE version already live for this
            scoring type, both are evaluated for comparison.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <FormField label="Reference tread depth (mm)">
              <input value={referenceTread} onChange={(e) => setReferenceTread(e.target.value)} style={inputStyle} />
            </FormField>
            <FormField label="Measured tread depth (mm)">
              <input value={measuredTread} onChange={(e) => setMeasuredTread(e.target.value)} style={inputStyle} />
            </FormField>
            <FormField label="KA score (optional)">
              <input value={kaScore} onChange={(e) => setKaScore(e.target.value)} style={inputStyle} />
            </FormField>
            <FormField label="Inspector-flagged critical safety fail">
              <select value={criticalFail ? '1' : '0'} onChange={(e) => setCriticalFail(e.target.value === '1')} style={inputStyle}>
                <option value="0">No</option>
                <option value="1">Yes</option>
              </select>
            </FormField>
          </div>
          <button className="btn-primary" onClick={runDryRun} disabled={running} style={{ marginTop: 8 }}>
            {running ? 'Running…' : 'Run Dry Run'}
          </button>
          {error && <div style={{ color: '#b91c1c', fontSize: 13, marginTop: 8 }}>{error}</div>}
          {result && (
            <div style={{ marginTop: 14 }}>
              <DryRunResultCard title="This draft/version" r={result.draft_result} />
              {result.active_configuration_version_id ? (
                <>
                  <DryRunResultCard title="Currently active version" r={result.active_result} />
                  <div style={{ fontSize: 13, marginTop: 8, color: result.changed_from_active ? '#b45309' : '#15803d' }}>
                    {result.changed_from_active ? 'This draft would change the outcome versus the currently active version.' : 'No change from the currently active version for this input.'}
                  </div>
                </>
              ) : (
                <div style={{ fontSize: 13, color: '#6b7280', marginTop: 8 }}>No other version is currently active for this scoring type.</div>
              )}
            </div>
          )}
        </Modal>
      )}
    </div>
  );
}

function DryRunResultCard({ title, r }: { title: string; r: DryRunResult | null }) {
  if (!r) return null;
  return (
    <div style={{ border: '1px solid #e5e7eb', borderRadius: 6, padding: 10, marginBottom: 8 }}>
      <strong style={{ fontSize: 13 }}>{title}</strong>
      {r.missing_inputs.length > 0 ? (
        <div style={{ fontSize: 13, color: '#b91c1c', marginTop: 4 }}>Missing inputs: {r.missing_inputs.join(', ')}</div>
      ) : (
        <table style={{ width: '100%', fontSize: 13, marginTop: 6 }}>
          <tbody>
            <tr>
              <td style={{ color: '#6b7280', padding: '2px 0' }}>SPA raw / normalized</td>
              <td>{r.spa_raw_percent}% / {r.spa_normalized_score}</td>
            </tr>
            <tr>
              <td style={{ color: '#6b7280', padding: '2px 0' }}>Classification</td>
              <td>{r.classification}</td>
            </tr>
            <tr>
              <td style={{ color: '#6b7280', padding: '2px 0' }}>KF score</td>
              <td>{r.kf_score ?? '—'}</td>
            </tr>
            <tr>
              <td style={{ color: '#6b7280', padding: '2px 0' }}>Critical safety fail</td>
              <td>{r.critical_safety_fail ? 'Yes' : 'No'}</td>
            </tr>
            <tr>
              <td style={{ color: '#6b7280', padding: '2px 0' }}>Eligible for operational reuse</td>
              <td>{r.eligible_for_operational_reuse ? 'Yes' : 'No'}</td>
            </tr>
          </tbody>
        </table>
      )}
    </div>
  );
}
