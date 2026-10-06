import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { Link, useParams } from "react-router-dom";
import {
  apiClient,
  extractApiError,
  type ApiErrorShape,
} from "../../../../api/client";
import { useAuth } from "../../../../auth/AuthContext";
import { BackButton } from "../../../../components/BackButton";
import { FormField, inputStyle } from "../../../../components/FormField";
import { InfoTip } from "../../../../components/InfoTip";
import { NumericInput } from "../../../../components/NumericInput";
import { ErrorState, LoadingState } from "../../../../components/States";
import { StatusBadge } from "../../../../components/StatusBadge";
import { PositionLabel } from "../../../../components/tires/PositionLabel";
import { useBreadcrumbLabel } from "../../../../navigation/BreadcrumbLabelContext";
import type { Warehouse } from "../../../../types";
import { formatKm } from "../operations/tireOperationFormat";
import { InspectionResultCard } from "./InspectionResultCard";
import {
  DAMAGE_TYPES,
  GROOVES,
  LOCATIONS,
  QUESTIONS,
  RECOMMENDATION_COLOR,
  TREAD_HELP,
  TRI_STATE,
  ZONE_HELP,
  damageSectionRelevant,
} from "./inspectionOptions";
import type {
  Damage,
  Evaluation,
  InspectionContext,
  Measurement,
} from "./inspectionTypes";
import { statusLabel } from '../../../../i18n/statusRegistry';
import { formatDateTime } from '../../../../utils/date';
import { t as tt } from '../../../../i18n/i18n';

type Answers = Record<string, string | null>;
type EvidenceDraft = {
  key: string;
  file: File;
  kind: "DAMAGE_PHOTO" | "CLOSE_UP_SCALE";
  damageIndex: number | null;
  notes: string;
};

const EMPTY_DAMAGE: Damage = {
  location: "TREAD",
  damage_type: "PUNCTURE",
  diameter_mm: null,
  length_mm: null,
  width_mm: null,
  depth_mm: null,
  reaches_reinforcement: "NO",
  overlaps_previous_repair: "NO",
  notes: null,
};

/**
 * Used Tire Management → Removed → Inspect. The questionnaire is grouped (identity, completeness,
 * tread, structure, sidewall/bead/liner, leak/heat/repair, damage details, evidence) and the
 * recommendation is evaluated live by the backend decision engine — the page never decides.
 * Submitting records the inspection; approving applies the disposition to the tire.
 */
export function UsedTireInspectionPage() {
  const { id = "" } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [context, setContext] = useState<InspectionContext | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [application, setApplication] = useState("");

  useEffect(() => {
    apiClient
      .get(`/app/tires/${id}/used-inspection/context`, {
        params: { application: application || undefined },
      })
      .then((res) => setContext(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [id, reloadKey, application]);
  useBreadcrumbLabel(context?.tire.id, context?.tire.serial_number);

  if (error && !context) return <ErrorState message={error} />;
  if (!context) return <LoadingState />;

  const reload = () => setReloadKey((k) => k + 1);
  const open = context.open_inspection;

  return (
    <div data-used-inspection-page>
      <BackButton
        fallbackTo="/app/used-tires?tab=removed"
        label={tt('tire.actions.backUsedTireManagement')}
      />
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          gap: 10,
          flexWrap: "wrap",
          marginBottom: 14,
        }}
      >
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {tt('tire.titles.tireInspection')}{" "}
          <span style={{ fontFamily: "monospace" }}>
            {context.tire.serial_number}
          </span>
        </h1>
        <StatusBadge status={context.tire.current_status} />
      </div>

      {open ? (
        <OpenInspection
          inspection={open}
          canApprove={hasPermission("tire_used_inspection.approve")}
          canInspect={hasPermission("tire.inspect")}
          onChanged={reload}
        />
      ) : context.can_inspect && hasPermission("tire.inspect") ? (
        <InspectionForm
          context={context}
          application={application}
          setApplication={setApplication}
          onSubmitted={reload}
          onIdentityUpdated={reload}
        />
      ) : (
        <section className="card" style={{ fontSize: 14 }}>
          {context.can_inspect
            ? tt('tire.help.youDoNotPermissionInspectTires')
            : tt('tire.help.onlyRemovedHoldTireInspectedHere', { current_status: statusLabel(context.tire.current_status) })}
        </section>
      )}

      {context.inspections.length > 0 && (
        <section
          className="card"
          style={{ marginTop: 16 }}
          data-inspection-list
        >
          <h3 style={{ marginTop: 0, fontSize: 15 }}>
            {tt('tire.sections.inspectionsOfThisTire')}
          </h3>
          <div style={{ overflowX: "auto" }}>
            <table
              style={{
                width: "100%",
                borderCollapse: "collapse",
                fontSize: 13,
              }}
            >
              <thead>
                <tr>
                  {[
                    tt('common.fields.date'),
                    tt('tire.fields.recommendation'),
                    tt('common.fields.status'),
                    tt('tire.fields.finalDisposition'),
                    tt('tire.fields.ruleVersion'),
                  ].map((h) => (
                    <th
                      key={h}
                      style={{
                        textAlign: "left",
                        padding: "6px 8px",
                        borderBottom: "1px solid #e5e7eb",
                      }}
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {context.inspections.map((i) => (
                  <tr key={i.id}>
                    <td style={{ padding: "6px 8px" }}>
                      {i.inspected_at
                        ? formatDateTime(i.inspected_at)
                        : "—"}
                    </td>
                    <td
                      style={{
                        padding: "6px 8px",
                        color: RECOMMENDATION_COLOR[i.recommendation],
                      }}
                    >
                      {i.recommendation}
                      {i.recommendation_detail === "RETREAD_CANDIDATE"
                        ? tt('tire.fields.retreadCandidate')
                        : ""}
                    </td>
                    <td style={{ padding: "6px 8px" }}>
                      <StatusBadge status={i.status} />
                    </td>
                    <td style={{ padding: "6px 8px" }}>
                      {i.final_disposition ?? "—"}
                    </td>
                    <td style={{ padding: "6px 8px" }}>
                      {i.rule_profile_version ?? "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ form

function InspectionForm({
  context,
  application,
  setApplication,
  onSubmitted,
  onIdentityUpdated,
}: {
  context: InspectionContext;
  application: string;
  setApplication: (v: string) => void;
  onSubmitted: () => void;
  /** The physical tire's identity changed (e.g. Manufacture Date Code filled in) — reload the facts. */
  onIdentityUpdated: () => void;
}) {
  const facts = context.tire;
  const [answers, setAnswers] = useState<Answers>({});
  const [depths, setDepths] = useState<Record<string, string>>({});
  const [dNew, setDNew] = useState(facts.d_new_default_mm ?? "");
  const [notes, setNotes] = useState("");
  const [damages, setDamages] = useState<Damage[]>([]);
  const [evidence, setEvidence] = useState<EvidenceDraft[]>([]);
  const [evaluation, setEvaluation] = useState<Evaluation | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const evalSeq = useRef(0);

  const measurements: Measurement[] = useMemo(
    () =>
      Object.entries(depths)
        .filter(([, v]) => v !== "")
        .map(([key, depth]) => {
          const [zone, groove] = key.split("|");
          return {
            zone: Number(zone),
            groove: groove as Measurement["groove"],
            depth_mm: depth,
          };
        }),
    [depths],
  );
  const payload = useMemo(
    () => ({
      ...answers,
      application: application || null,
      measurements,
      damages,
      d_new_mm: dNew || null,
      notes: notes || null,
    }),
    [answers, application, measurements, damages, dNew, notes],
  );

  // Live recommendation from the backend (debounced; stale responses are ignored).
  useEffect(() => {
    const seq = ++evalSeq.current;
    const timer = setTimeout(() => {
      apiClient
        .post(`/app/tires/${facts.id}/used-inspection/evaluate`, payload)
        .then((res) => seq === evalSeq.current && setEvaluation(res.data.data))
        .catch(() => undefined);
    }, 350);
    return () => clearTimeout(timer);
  }, [payload, facts.id, facts.age_months]);

  const set = (key: string) => (value: string) =>
    setAnswers((a) => ({ ...a, [key]: value || null }));
  const showDamages =
    damages.length > 0 ||
    damageSectionRelevant(answers) ||
    !!evaluation?.shows_damage_section;
  const showSpecialist =
    !!evaluation?.shows_specialist_question ||
    answers.repair_eligibility === "SPECIALIST_REQUIRED";

  async function submit() {
    setBusy(true);
    setErrors({});
    setSubmitError(null);
    try {
      const res = await apiClient.post(
        `/app/tires/${facts.id}/used-inspections`,
        payload,
      );
      const inspection = res.data.data as {
        id: string;
        damages: { id: string }[];
      };
      for (const draft of evidence) {
        const form = new FormData();
        form.append("file", draft.file);
        form.append("kind", draft.kind);
        if (draft.damageIndex != null && inspection.damages[draft.damageIndex])
          form.append("damage_id", inspection.damages[draft.damageIndex].id);
        if (draft.notes) form.append("notes", draft.notes);
        await apiClient.post(
          `/app/tire-used-inspections/${inspection.id}/evidence`,
          form,
          { headers: { "Content-Type": "multipart/form-data" } },
        );
      }
      onSubmitted();
    } catch (e) {
      const api: ApiErrorShape = extractApiError(e);
      setErrors(api.errors ?? {});
      setSubmitError(api.message);
    } finally {
      setBusy(false);
    }
  }

  const unanswered = Object.keys(QUESTIONS).filter(
    (k) =>
      !["repair_eligibility", "specialist_result"].includes(k) && !answers[k],
  );

  return (
    <div className="split-layout" style={{ alignItems: "start" }}>
      <div style={{ display: "grid", gap: 14, minWidth: 0 }}>
        <Group n={1} title={tt('tire.sections.tireIdentity')}>
          <dl
            style={{
              display: "grid",
              gridTemplateColumns:
                "repeat(auto-fit, minmax(min(100%, 220px), 1fr))",
              gap: "6px 16px",
              fontSize: 13,
              margin: 0,
            }}
            data-auto-filled
          >
            <Fact
              label={tt('tire.fields.tireIdSerial')}
              value={
                <span style={{ fontFamily: "monospace" }}>
                  {facts.serial_number}
                </span>
              }
            />
            <Fact label={tt('common.fields.brand')} value={facts.brand} />
            <Fact label={tt('common.fields.model')} value={facts.model} />
            <Fact label={tt('tire.fields.size')} value={facts.size} />
            <Fact label={tt('inventory.fields.construction')} value={facts.construction} />
            <Fact
              label={tt('tire.fields.tireCategory')}
              value={
                facts.category_label ??
                tt('tire.fields.unknownSetProductVehicleGroup')
              }
            />
            <Fact
              label={tt('tire.fields.manufactureDateCode')}
              value={
                <ManufactureDateCode
                  tireId={facts.id}
                  code={facts.manufacture_date_code}
                  onSaved={onIdentityUpdated}
                />
              }
            />
            <Fact
              label={tt('tire.fields.tireAge')}
              value={
                facts.age_months != null
                  ? `${facts.age_months} months`
                  : tt('tire.fields.unknown')
              }
            />
            <Fact label={tt('tire.fields.retreadCount')} value={String(facts.retread_count)} />
            <Fact
              label={tt('tire.fields.repairHistory')}
              value={
                facts.repair_history.length
                  ? facts.repair_history.map((r) => r.label).join("; ")
                  : tt('common.fields.none')
              }
            />
            <Fact label={tt('tire.fields.lastVehicle')} value={facts.last_vehicle} />
            <Fact
              label={tt('tire.fields.lastPosition')}
              value={
                facts.last_position ? (
                  <PositionLabel code={facts.last_position} />
                ) : null
              }
            />
            <Fact label={tt('tire.fields.usageKm')} value={formatKm(facts.usage_km)} />
            <Fact label={tt('tenantComponents.fields.removalReason')} value={facts.removal_reason} />
            <Fact label={tt('tire.fields.inspector')} value={facts.inspector} />
            <Fact label={tt('tire.fields.inspectionDateTime')} value={facts.inspection_at} />
          </dl>
          <div
            style={{
              display: "flex",
              gap: 12,
              flexWrap: "wrap",
              marginTop: 10,
              alignItems: "flex-end",
            }}
          >
            {context.applications.length > 0 && (
              <FormField label={tt('tire.fields.applicationRuleProfile')}>
                <select
                  aria-label={tt('tire.fields.application')}
                  value={application}
                  onChange={(e) => setApplication(e.target.value)}
                  style={{ ...inputStyle, width: 200 }}
                >
                  <option value="">{tt('common.fields.general')}</option>
                  {context.applications.map((a) => (
                    <option key={a} value={a}>
                      {a}
                    </option>
                  ))}
                </select>
              </FormField>
            )}
            <div style={{ fontSize: 12, color: "#6b7280" }} data-rule-profile>
              {context.rule_profile
                ? tt('tire.help.ruleProfileNameVVersionD', { name: context.rule_profile.name, version: context.rule_profile.version, d_service_mm: context.rule_profile.d_service_mm, d_pull_mm: context.rule_profile.d_pull_mm, a_max_months: context.rule_profile.a_max_months, a_retread_max_months: context.rule_profile.a_retread_max_months, n_retread_max: context.rule_profile.n_retread_max })
                : tt('tire.empty.noActiveRuleProfileTireCategory')}
            </div>
          </div>
        </Group>

        <Group n={2} title={tt('tire.sections.inspectionCompleteness')}>
          <Question
            k="identity_status"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="internal_inspected"
            answers={answers}
            onChange={set}
            errors={errors}
          />
        </Group>

        <Group n={3} title={tt('tire.sections.tread')}>
          <p style={{ fontSize: 12, color: "#6b7280", marginTop: 0 }}>
            {tt('tire.help.least6Points3CircumferentialZones')}
          </p>
          <div style={{ overflowX: "auto" }}>
            <table
              style={{ borderCollapse: "collapse", fontSize: 13 }}
              data-tread-grid
            >
              <thead>
                <tr>
                  <th style={{ textAlign: "left", padding: 4 }}>
                    {tt('tire.fields.treadDepthMm')}
                  </th>
                  {[1, 2, 3].map((z) => (
                    <th key={z} style={{ padding: 4, whiteSpace: "nowrap" }}>
                      Zone {z}
                      <InfoTip label={tt('tire.fields.zoneZ', { z: z })}>{ZONE_HELP[z]}</InfoTip>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {GROOVES.map((g) => (
                  <tr key={g.value}>
                    <td style={{ padding: 4, whiteSpace: "nowrap" }}>
                      {g.label} (mm)
                      {g.required ? " *" : ""}
                      <InfoTip label={g.label}>{g.help}</InfoTip>
                    </td>
                    {[1, 2, 3].map((z) => (
                      <td key={z} style={{ padding: 4 }}>
                        <NumericInput
                          aria-label={tt('tire.fields.treadZoneZLabel', { z: z, label: g.label })}
                          step="0.1"
                          value={depths[`${z}|${g.value}`] ?? ""}
                          onChange={(e) =>
                            setDepths((d) => ({
                              ...d,
                              [`${z}|${g.value}`]: e.target.value,
                            }))
                          }
                          style={{ ...inputStyle, width: 90 }}
                        />{" "}
                        <span style={{ fontSize: 12, color: "#6b7280" }}>
                          {tt('tire.help.mm')}
                        </span>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div
            style={{
              display: "flex",
              gap: 16,
              flexWrap: "wrap",
              alignItems: "flex-end",
              marginTop: 8,
            }}
          >
            <FormField
              label={tt('tire.fields.dNewTreadWhenNewAfter')}
              hint={TREAD_HELP.d_new}
            >
              <NumericInput
                aria-label={tt('tire.fields.dNew')}
                step="0.1"
                value={dNew}
                onChange={(e) => setDNew(e.target.value)}
                style={{ ...inputStyle, width: 140 }}
              />
            </FormField>
            <div style={{ fontSize: 13 }} data-dmin>
              {tt('tire.fields.dMinMm')}
              <InfoTip label="D_min">{TREAD_HELP.d_min}</InfoTip>:{" "}
              <strong>
                {evaluation?.d_min_mm != null
                  ? tt('tire.help.dPullMmMm', { d_pull_mm: evaluation.d_min_mm })
                  : "—"}
              </strong>
              {evaluation?.remaining_tread_percent != null && (
                <span style={{ color: "#6b7280" }}>
                  {" "}
                  · Remaining tread {evaluation.remaining_tread_percent}%{" "}
                  <em>{tt('tire.help.indicatorOnlyNotSafetyScore')}</em>
                </span>
              )}
            </div>
            <div style={{ fontSize: 13 }} data-dpull>
              {tt('tire.fields.dPullMm')}
              <InfoTip label="D_pull">{TREAD_HELP.d_pull}</InfoTip>:{" "}
              <strong>
                {context.rule_profile?.d_pull_mm != null
                  ? tt('tire.help.dPullMmMm', { d_pull_mm: context.rule_profile.d_pull_mm })
                  : "—"}
              </strong>
              <span style={{ color: "#6b7280" }}>
                {context.rule_profile
                  ? ` · D_service ${context.rule_profile.d_service_mm} mm`
                  : tt('tire.help.noActiveRuleProfileTireManagement')}
              </span>
            </div>
          </div>
          {errors.measurements && (
            <div style={{ color: "#b91c1c", fontSize: 12 }}>
              {errors.measurements[0]}
            </div>
          )}
        </Group>

        <Group n={4} title={tt('tire.sections.structuralCondition')}>
          <Question
            k="wear_pattern"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="bulge_separation"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="cord_exposure"
            answers={answers}
            onChange={set}
            errors={errors}
          />
        </Group>

        <Group n={5} title={tt('tire.sections.sidewallBeadInnerLiner')}>
          <Question
            k="sidewall_condition"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="bead_condition"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="inner_liner_condition"
            answers={answers}
            onChange={set}
            errors={errors}
          />
        </Group>

        <Group n={6} title={tt('tire.sections.leakageHeatPreviousRepair')}>
          <Question
            k="run_flat_overheat"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="leak_foreign_object"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="previous_repair"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="age_chemical"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          <Question
            k="casing_compliance"
            answers={answers}
            onChange={set}
            errors={errors}
          />
          {showSpecialist && (
            <Question
              k="specialist_result"
              answers={answers}
              onChange={set}
              errors={errors}
            />
          )}
        </Group>

        {showDamages && (
          <Group n={7} title={tt('tire.sections.damageDetails')}>
            {damages.map((d, i) => (
              <DamageRow
                key={i}
                index={i}
                damage={d}
                onChange={(next) =>
                  setDamages((all) => all.map((x, j) => (j === i ? next : x)))
                }
                onRemove={() =>
                  setDamages((all) => all.filter((_, j) => j !== i))
                }
              />
            ))}
            <button
              type="button"
              className="btn-secondary"
              onClick={() => setDamages((all) => [...all, { ...EMPTY_DAMAGE }])}
              data-add-damage
            >
              {tt('tire.actions.addDamage')}
            </button>
            {damages.length > 0 && (
              <Question
                k="repair_eligibility"
                answers={answers}
                onChange={set}
                errors={errors}
              />
            )}
          </Group>
        )}

        <Group n={showDamages ? 8 : 7} title={tt('tire.sections.evidence')}>
          <EvidencePicker
            drafts={evidence}
            damages={damages}
            onChange={setEvidence}
          />
          <FormField label={tt('tire.placeholders.notes')}>
            <textarea
              aria-label={tt('tire.fields.inspectionNotes')}
              value={notes}
              maxLength={2000}
              onChange={(e) => setNotes(e.target.value)}
              style={{ ...inputStyle, minHeight: 60 }}
            />
          </FormField>
        </Group>
      </div>

      <div
        style={{
          position: "sticky",
          top: 12,
          display: "grid",
          gap: 12,
          minWidth: 0,
        }}
      >
        <Group n={showDamages ? 9 : 8} title={tt('tire.fields.recommendation')}>
          <RecommendationPanel evaluation={evaluation} />
        </Group>
        <Group n={showDamages ? 10 : 9} title={tt('tire.fields.finalDisposition')}>
          <p style={{ fontSize: 13, marginTop: 0 }}>
            {tt('tire.help.submittingRecordsInspectionRecommendationTireKeeps')}
          </p>
          {unanswered.length > 0 && (
            <p style={{ fontSize: 12, color: "#b45309" }}>
              {unanswered.length} question(s) still unanswered.
            </p>
          )}
          {submitError && <ErrorState message={submitError} />}
          <button
            type="button"
            className="btn-primary"
            disabled={busy || unanswered.length > 0}
            onClick={submit}
            data-submit-inspection
          >
            {busy ? tt('common.actions.submitting') : tt('tire.actions.submitInspection')}
          </button>
        </Group>
      </div>
    </div>
  );
}

function RecommendationPanel({
  evaluation,
}: {
  evaluation: Evaluation | null;
}) {
  if (!evaluation)
    return (
      <p style={{ fontSize: 13, color: "#6b7280", margin: 0 }}>
        {tt('tire.help.answerQuestionsSeeRecommendation')}
      </p>
    );
  const color = RECOMMENDATION_COLOR[evaluation.recommendation];
  const incomplete =
    evaluation.recommendation === "HOLD" &&
    evaluation.recommendation_detail !== "RETREAD_CANDIDATE";
  return (
    <div data-live-recommendation={evaluation.recommendation}>
      {incomplete && (
        <div
          role="status"
          data-hold-indicator
          style={{
            background: "#fffbeb",
            border: "1px solid #fde68a",
            color: "#92400e",
            borderRadius: 6,
            padding: "8px 10px",
            fontSize: 13,
            marginBottom: 8,
          }}
        >
          {tt('tire.help.inspectionIncompleteUncertainRecommendedStatus')}:{" "}
          <strong>{tt('tire.fields.hold')}</strong>
        </div>
      )}
      <div style={{ fontSize: 18, fontWeight: 700, color }}>
        {evaluation.recommendation}
        {evaluation.recommendation_detail === "RETREAD_CANDIDATE"
          ? tt('tire.fields.retreadCandidate')
          : ""}
        {evaluation.additional_work === "CASING_REPAIR"
          ? tt('tire.fields.casingRepair')
          : ""}
      </div>
      <div style={{ fontSize: 12, color: "#6b7280", margin: "6px 0 2px" }}>
        {tt('common.fields.reason')}
      </div>
      <ul
        style={{ margin: 0, paddingLeft: 18, fontSize: 13 }}
        data-live-reasons
      >
        {evaluation.reasons.map((r) => (
          <li key={r}>{r}</li>
        ))}
      </ul>
      {evaluation.follow_ups.length > 0 && (
        <>
          <div style={{ fontSize: 12, color: "#6b7280", margin: "8px 0 2px" }}>
            {tt('tire.help.vehicleFollowUpNotFixedTire')}
          </div>
          <ul style={{ margin: 0, paddingLeft: 18, fontSize: 13 }}>
            {evaluation.follow_ups.map((f) => (
              <li key={f}>{f}</li>
            ))}
          </ul>
        </>
      )}
      <div
        style={{ fontSize: 11, color: "#6b7280", marginTop: 8 }}
        data-variables
      >
        {Object.entries(evaluation.variables)
          .map(([k, v]) => `${k}=${v === null ? "–" : v ? "yes" : "no"}`)
          .join(" · ")}
      </div>
    </div>
  );
}

// ------------------------------------------------------- submitted / result

function OpenInspection({
  inspection,
  canApprove,
  canInspect,
  onChanged,
}: {
  inspection: NonNullable<InspectionContext["open_inspection"]>;
  canApprove: boolean;
  canInspect: boolean;
  onChanged: () => void;
}) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const isReuse = inspection.recommendation === "REUSE";

  useEffect(() => {
    if (!isReuse) return;
    apiClient
      .get("/app/warehouses", { params: { status: "ACTIVE", per_page: 100 } })
      .then((res) => setWarehouses(res.data.data))
      .catch(() => setWarehouses([]));
  }, [isReuse]);

  const act = useCallback(
    async (path: "approve" | "cancel") => {
      setBusy(true);
      setError(null);
      try {
        await apiClient.post(
          `/app/tire-used-inspections/${inspection.id}/${path}`,
          path === "approve"
            ? {
                warehouse_id: warehouseId || undefined,
                note: note || undefined,
              }
            : {},
        );
        onChanged();
      } catch (e) {
        setError(extractApiError(e).message);
      } finally {
        setBusy(false);
      }
    },
    [inspection.id, warehouseId, note, onChanged],
  );

  return (
    <InspectionResultCard
      inspection={inspection}
      actions={
        <div style={{ display: "grid", gap: 8 }}>
          {error && <ErrorState message={error} />}
          {canApprove && (
            <div
              style={{
                display: "flex",
                gap: 10,
                flexWrap: "wrap",
                alignItems: "flex-end",
              }}
            >
              {isReuse && (
                <FormField label={tt('tire.fields.returnToWarehouse')} required>
                  <select
                    aria-label={tt('tire.fields.returnToWarehouse2')}
                    value={warehouseId}
                    onChange={(e) => setWarehouseId(e.target.value)}
                    style={{ ...inputStyle, width: 220 }}
                  >
                    <option value="">{tt('common.fields.select')}</option>
                    {warehouses.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name}
                      </option>
                    ))}
                  </select>
                </FormField>
              )}
              <FormField label={tt('tire.fields.approvalNote')}>
                <input
                  aria-label={tt('tire.fields.approvalNote')}
                  value={note}
                  maxLength={500}
                  onChange={(e) => setNote(e.target.value)}
                  style={{ ...inputStyle, width: 260 }}
                />
              </FormField>
              <button
                type="button"
                className="btn-primary"
                disabled={busy || (isReuse && !warehouseId)}
                onClick={() => act("approve")}
                data-approve
              >
                {tt('tire.actions.approveDispositionRecommendation', { recommendation: inspection.recommendation })}</button>
            </div>
          )}
          {!canApprove && (
            <p style={{ fontSize: 13, margin: 0 }}>
              {tt('tire.help.waitingApproverPermissionApproveUsedTire')}
            </p>
          )}
          {canInspect && (
            <div>
              <button
                type="button"
                className="btn-secondary"
                disabled={busy}
                onClick={() => act("cancel")}
              >
                {tt('tire.actions.cancelInspectionInspectAgain')}
              </button>
            </div>
          )}
          <Link
            to={`/app/tires/${inspection.tire_id}`}
            style={{ fontSize: 13 }}
          >
            {tt('tire.actions.openTireDetails')}
          </Link>
        </div>
      }
    />
  );
}

// ------------------------------------------------------------- pieces

function Group({
  n,
  title,
  children,
}: {
  n: number;
  title: string;
  children: ReactNode;
}) {
  return (
    <section className="card" data-inspection-group={title}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>
        {n}. {title}
      </h3>
      <div style={{ display: "grid", gap: 10 }}>{children}</div>
    </section>
  );
}

function Question({
  k,
  answers,
  onChange,
  errors,
}: {
  k: string;
  answers: Answers;
  onChange: (key: string) => (value: string) => void;
  errors: Record<string, string[]>;
}) {
  const q = QUESTIONS[k];
  return (
    <FormField label={q.label} errors={errors[k]} hint={q.help}>
      <select
        aria-label={q.label}
        data-question={k}
        value={answers[k] ?? ""}
        onChange={(e) => onChange(k)(e.target.value)}
        style={inputStyle}
      >
        <option value="">{tt('common.fields.select')}</option>
        {q.options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
    </FormField>
  );
}

function DamageRow({
  index,
  damage,
  onChange,
  onRemove,
}: {
  index: number;
  damage: Damage;
  onChange: (d: Damage) => void;
  onRemove: () => void;
}) {
  const set = (key: keyof Damage, value: string) =>
    onChange({ ...damage, [key]: value === "" ? null : value });
  const num = (
    key: "diameter_mm" | "length_mm" | "width_mm" | "depth_mm",
    label: string,
  ) => (
    <FormField label={tt('tire.fields.labelMm', { label: label })}>
      <NumericInput
        aria-label={tt('tire.fields.damageValueLabel', { value: index + 1, label: label })}
        step="0.1"
        value={damage[key] ?? ""}
        onChange={(e) => set(key, e.target.value)}
        style={{ ...inputStyle, width: 100 }}
      />
    </FormField>
  );
  return (
    <div
      data-damage-row={index}
      style={{ border: "1px solid #e5e7eb", borderRadius: 8, padding: 10 }}
    >
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          marginBottom: 6,
        }}
      >
        <strong style={{ fontSize: 13 }}>{tt('tire.fields.damageValue', { value: index + 1 })}</strong>
        <button type="button" className="btn-link" onClick={onRemove}>
          {tt('common.actions.remove')}
        </button>
      </div>
      <div
        style={{
          display: "flex",
          gap: 10,
          flexWrap: "wrap",
          alignItems: "flex-end",
        }}
      >
        <FormField label={tt('maintenance.fields.location')}>
          <select
            aria-label={tt('tire.fields.damageValueLocation', { value: index + 1 })}
            value={damage.location}
            onChange={(e) => set("location", e.target.value)}
            style={{ ...inputStyle, width: 140 }}
          >
            {LOCATIONS.map((l) => (
              <option key={l.value} value={l.value}>
                {l.label}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('common.fields.type')}>
          <select
            aria-label={tt('tire.fields.damageValueType', { value: index + 1 })}
            value={damage.damage_type}
            onChange={(e) => set("damage_type", e.target.value)}
            style={{ ...inputStyle, width: 180 }}
          >
            {DAMAGE_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </FormField>
        {damage.damage_type === "PUNCTURE" && num("diameter_mm", tt('tire.fields.diameter'))}
        {damage.damage_type === "CUT" && (
          <>
            {num("length_mm", tt('tire.fields.length'))}
            {num("width_mm", tt('tire.fields.width'))}
            {num("depth_mm", tt('tire.fields.depth'))}
          </>
        )}
        <FormField label={tt('tire.fields.reachesTheReinforcingStructure')}>
          <select
            aria-label={tt('tire.fields.damageValueReinforcement', { value: index + 1 })}
            value={damage.reaches_reinforcement}
            onChange={(e) => set("reaches_reinforcement", e.target.value)}
            style={{ ...inputStyle, width: 120 }}
          >
            {TRI_STATE.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('tire.fields.overlapsAPreviousRepair')}>
          <select
            aria-label={tt('tire.fields.damageValueOverlap', { value: index + 1 })}
            value={damage.overlaps_previous_repair}
            onChange={(e) => set("overlaps_previous_repair", e.target.value)}
            style={{ ...inputStyle, width: 120 }}
          >
            {TRI_STATE.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('tire.placeholders.notes')}>
          <input
            aria-label={tt('tire.fields.damageValueNotes', { value: index + 1 })}
            value={damage.notes ?? ""}
            onChange={(e) => set("notes", e.target.value)}
            style={{ ...inputStyle, width: 200 }}
          />
        </FormField>
      </div>
    </div>
  );
}

function EvidencePicker({
  drafts,
  damages,
  onChange,
}: {
  drafts: EvidenceDraft[];
  damages: Damage[];
  onChange: (d: EvidenceDraft[]) => void;
}) {
  return (
    <div style={{ display: "grid", gap: 8 }} data-evidence-picker>
      <input
        type="file"
        aria-label={tt('tire.fields.evidencePhotos')}
        accept=".jpg,.jpeg,.png,image/jpeg,image/png"
        multiple
        onChange={(e) => {
          const files = Array.from(e.target.files ?? []);
          onChange([
            ...drafts,
            ...files.map((file) => ({
              key: `${file.name}-${file.size}-${Math.random()}`,
              file,
              kind: "DAMAGE_PHOTO" as const,
              damageIndex: null,
              notes: "",
            })),
          ]);
          e.target.value = "";
        }}
      />
      <span style={{ fontSize: 12, color: "#6b7280" }}>
        {tt('tire.help.jpgPngMax3MbEach')}
      </span>
      {drafts.map((d) => (
        <div
          key={d.key}
          style={{
            display: "flex",
            gap: 8,
            flexWrap: "wrap",
            alignItems: "center",
            fontSize: 13,
          }}
        >
          <span style={{ minWidth: 140 }}>{d.file.name}</span>
          <select
            aria-label={tt('tire.fields.evidenceKindName', { name: d.file.name })}
            value={d.kind}
            onChange={(e) =>
              onChange(
                drafts.map((x) =>
                  x.key === d.key
                    ? { ...x, kind: e.target.value as EvidenceDraft["kind"] }
                    : x,
                ),
              )
            }
            style={{ ...inputStyle, width: 190 }}
          >
            <option value="DAMAGE_PHOTO">{tt('tire.fields.damagePhoto2')}</option>
            <option value="CLOSE_UP_SCALE">{tt('tire.fields.closeUpWithScale2')}</option>
          </select>
          {damages.length > 0 && (
            <select
              aria-label={tt('tire.fields.evidenceDamageName', { name: d.file.name })}
              value={d.damageIndex ?? ""}
              onChange={(e) =>
                onChange(
                  drafts.map((x) =>
                    x.key === d.key
                      ? {
                          ...x,
                          damageIndex:
                            e.target.value === ""
                              ? null
                              : Number(e.target.value),
                        }
                      : x,
                  ),
                )
              }
              style={{ ...inputStyle, width: 140 }}
            >
              <option value="">{tt('tire.fields.wholeTire')}</option>
              {damages.map((_, i) => (
                <option key={i} value={i}>
                  {tt('tire.fields.damageValue', { value: i + 1 })}</option>
              ))}
            </select>
          )}
          <input
            aria-label={tt('tire.fields.evidenceNotesName', { name: d.file.name })}
            placeholder={tt('tire.placeholders.notes')}
            value={d.notes}
            onChange={(e) =>
              onChange(
                drafts.map((x) =>
                  x.key === d.key ? { ...x, notes: e.target.value } : x,
                ),
              )
            }
            style={{ ...inputStyle, width: 180 }}
          />
          <button
            type="button"
            className="btn-link"
            onClick={() => onChange(drafts.filter((x) => x.key !== d.key))}
          >
            {tt('common.actions.remove')}
          </button>
        </div>
      ))}
    </div>
  );
}

/**
 * Tire Identity → Manufacture Date Code. When the physical tire has no code yet, Edit lets the
 * inspector fill it in; it is saved on the tire and the backend returns the new Tire Age.
 */
function ManufactureDateCode({
  tireId,
  code,
  onSaved,
}: {
  tireId: string;
  code: string | null;
  onSaved: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  if (code) return <span data-mdc-value>{code}</span>;
  if (!editing)
    return (
      <span style={{ display: "inline-flex", gap: 8, alignItems: "center" }}>
        <span data-mdc-value>—</span>
        <button
          type="button"
          className="btn-secondary"
          data-mdc-edit
          onClick={() => setEditing(true)}
          style={{ padding: "1px 8px", fontSize: 12 }}
        >
          {tt('common.actions.edit')}
        </button>
      </span>
    );

  async function save() {
    setSaving(true);
    setError(null);
    try {
      await apiClient.put(`/app/tires/${tireId}/manufacture-date-code`, {
        manufacture_date_code: value,
      });
      setEditing(false);
      onSaved();
    } catch (e) {
      const api = extractApiError(e);
      setError(api.errors?.manufacture_date_code?.[0] ?? api.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <span style={{ display: "grid", gap: 4 }} data-mdc-form>
      <span style={{ display: "inline-flex", gap: 6, alignItems: "center" }}>
        <input
          aria-label={tt('tire.fields.manufactureDateCode')}
          placeholder={tt('tire.placeholders.wwyyEG1225')}
          value={value}
          maxLength={20}
          autoFocus
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && value.trim()) void save();
            if (e.key === "Escape") setEditing(false);
          }}
          style={{ ...inputStyle, width: 120, padding: "3px 6px" }}
        />
        <button
          type="button"
          className="btn-primary"
          disabled={saving || !value.trim()}
          onClick={() => void save()}
          style={{ padding: "2px 8px", fontSize: 12 }}
        >
          {saving ? tt('common.actions.saving') : tt('common.actions.save')}
        </button>
        <button
          type="button"
          className="btn-secondary"
          disabled={saving}
          onClick={() => {
            setEditing(false);
            setError(null);
          }}
          style={{ padding: "2px 8px", fontSize: 12 }}
        >
          {tt('common.actions.cancel')}
        </button>
      </span>
      <span style={{ fontSize: 11, color: "#6b7280" }}>
        {tt('tire.help.dotDateCodeProductionWeek2')}
      </span>
      {error && (
        <span role="alert" style={{ fontSize: 12, color: "#b91c1c" }}>
          {error}
        </span>
      )}
    </span>
  );
}

function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt style={{ color: "#6b7280", fontSize: 12 }}>{label}</dt>
      <dd style={{ margin: 0 }}>{value ?? "—"}</dd>
    </div>
  );
}
