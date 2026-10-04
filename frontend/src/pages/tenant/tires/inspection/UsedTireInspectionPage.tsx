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
  TRI_STATE,
  damageSectionRelevant,
} from "./inspectionOptions";
import type {
  Damage,
  Evaluation,
  InspectionContext,
  Measurement,
} from "./inspectionTypes";

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
        label="← Back to Used Tire Management"
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
          Tire Inspection —{" "}
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
        />
      ) : (
        <section className="card" style={{ fontSize: 14 }}>
          {context.can_inspect
            ? "You do not have permission to inspect tires."
            : `Only a REMOVED or HOLD tire is inspected here — this tire is ${context.tire.current_status}.`}
        </section>
      )}

      {context.inspections.length > 0 && (
        <section
          className="card"
          style={{ marginTop: 16 }}
          data-inspection-list
        >
          <h3 style={{ marginTop: 0, fontSize: 15 }}>
            Inspections of this tire
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
                    "Date",
                    "Recommendation",
                    "Status",
                    "Final Disposition",
                    "Rule Version",
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
                        ? new Date(i.inspected_at).toLocaleString()
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
                        ? " — Retread Candidate"
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
}: {
  context: InspectionContext;
  application: string;
  setApplication: (v: string) => void;
  onSubmitted: () => void;
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
  }, [payload, facts.id]);

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
        <Group n={1} title="Tire Identity">
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
              label="Tire ID / Serial"
              value={
                <span style={{ fontFamily: "monospace" }}>
                  {facts.serial_number}
                </span>
              }
            />
            <Fact label="Brand" value={facts.brand} />
            <Fact label="Model" value={facts.model} />
            <Fact label="Size" value={facts.size} />
            <Fact label="Construction" value={facts.construction} />
            <Fact
              label="Tire Category"
              value={
                facts.category_label ??
                "Unknown — set the product Vehicle Group"
              }
            />
            <Fact
              label="Manufacture Date Code"
              value={facts.manufacture_date_code}
            />
            <Fact
              label="Tire Age"
              value={
                facts.age_months != null
                  ? `${facts.age_months} months`
                  : "Unknown"
              }
            />
            <Fact label="Retread Count" value={String(facts.retread_count)} />
            <Fact
              label="Repair History"
              value={
                facts.repair_history.length
                  ? facts.repair_history.map((r) => r.label).join("; ")
                  : "None"
              }
            />
            <Fact label="Last Vehicle" value={facts.last_vehicle} />
            <Fact
              label="Last Position"
              value={
                facts.last_position ? (
                  <PositionLabel code={facts.last_position} />
                ) : null
              }
            />
            <Fact label="Usage KM" value={formatKm(facts.usage_km)} />
            <Fact label="Removal Reason" value={facts.removal_reason} />
            <Fact label="Inspector" value={facts.inspector} />
            <Fact label="Inspection Date / Time" value={facts.inspection_at} />
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
              <FormField label="Application (rule profile)">
                <select
                  aria-label="Application"
                  value={application}
                  onChange={(e) => setApplication(e.target.value)}
                  style={{ ...inputStyle, width: 200 }}
                >
                  <option value="">General</option>
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
                ? `Rule profile: ${context.rule_profile.name} (v${context.rule_profile.version}) — D_service ${context.rule_profile.d_service_mm} mm, D_pull ${context.rule_profile.d_pull_mm} mm, A_max ${context.rule_profile.a_max_months} mo, A_retread_max ${context.rule_profile.a_retread_max_months} mo, N_retread_max ${context.rule_profile.n_retread_max}`
                : "No active rule profile for this tire category — the result will be HOLD until one is configured (Tire Management → Inspection Rules)."}
            </div>
          </div>
        </Group>

        <Group n={2} title="Inspection Completeness">
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

        <Group n={3} title="Tread">
          <p style={{ fontSize: 12, color: "#6b7280", marginTop: 0 }}>
            At least 6 points: 3 circumferential zones × main groove inner /
            outer (center / most worn optional). Do not measure on the wear
            indicator.
          </p>
          <div style={{ overflowX: "auto" }}>
            <table
              style={{ borderCollapse: "collapse", fontSize: 13 }}
              data-tread-grid
            >
              <thead>
                <tr>
                  <th style={{ textAlign: "left", padding: 4 }}>mm</th>
                  {[1, 2, 3].map((z) => (
                    <th key={z} style={{ padding: 4 }}>
                      Zone {z}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {GROOVES.map((g) => (
                  <tr key={g.value}>
                    <td style={{ padding: 4, whiteSpace: "nowrap" }}>
                      {g.label}
                      {g.required ? " *" : ""}
                    </td>
                    {[1, 2, 3].map((z) => (
                      <td key={z} style={{ padding: 4 }}>
                        <NumericInput
                          aria-label={`Tread zone ${z} ${g.label}`}
                          step="0.1"
                          value={depths[`${z}|${g.value}`] ?? ""}
                          onChange={(e) =>
                            setDepths((d) => ({
                              ...d,
                              [`${z}|${g.value}`]: e.target.value,
                            }))
                          }
                          style={{ ...inputStyle, width: 90 }}
                        />
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
            <FormField label="D_new (tread when new / after last retread, mm)">
              <NumericInput
                aria-label="D new"
                step="0.1"
                value={dNew}
                onChange={(e) => setDNew(e.target.value)}
                style={{ ...inputStyle, width: 140 }}
              />
            </FormField>
            <div style={{ fontSize: 13 }} data-dmin>
              D_min:{" "}
              <strong>
                {evaluation?.d_min_mm != null
                  ? `${evaluation.d_min_mm} mm`
                  : "—"}
              </strong>
              {evaluation?.remaining_tread_percent != null && (
                <span style={{ color: "#6b7280" }}>
                  {" "}
                  · Remaining tread {evaluation.remaining_tread_percent}%{" "}
                  <em>(indicator only — not a safety score)</em>
                </span>
              )}
            </div>
          </div>
          {errors.measurements && (
            <div style={{ color: "#b91c1c", fontSize: 12 }}>
              {errors.measurements[0]}
            </div>
          )}
        </Group>

        <Group n={4} title="Structural Condition">
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

        <Group n={5} title="Sidewall / Bead / Inner Liner">
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

        <Group n={6} title="Leakage / Heat / Previous Repair">
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
          <Group n={7} title="Damage Details">
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
              + Add damage
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

        <Group n={showDamages ? 8 : 7} title="Evidence">
          <EvidencePicker
            drafts={evidence}
            damages={damages}
            onChange={setEvidence}
          />
          <FormField label="Notes">
            <textarea
              aria-label="Inspection notes"
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
        <Group n={showDamages ? 9 : 8} title="Recommendation">
          <RecommendationPanel evaluation={evaluation} />
        </Group>
        <Group n={showDamages ? 10 : 9} title="Final Disposition">
          <p style={{ fontSize: 13, marginTop: 0 }}>
            Submitting records this inspection with its recommendation. The tire
            keeps its status until the disposition is approved.
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
            {busy ? "Submitting…" : "Submit inspection"}
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
        Answer the questions to see the recommendation.
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
          Inspection incomplete / uncertain — Recommended status:{" "}
          <strong>HOLD</strong>
        </div>
      )}
      <div style={{ fontSize: 18, fontWeight: 700, color }}>
        {evaluation.recommendation}
        {evaluation.recommendation_detail === "RETREAD_CANDIDATE"
          ? " — Retread Candidate"
          : ""}
        {evaluation.additional_work === "CASING_REPAIR"
          ? " + Casing Repair"
          : ""}
      </div>
      <div style={{ fontSize: 12, color: "#6b7280", margin: "6px 0 2px" }}>
        Reason
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
            Vehicle follow-up (not fixed by a tire repair)
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
                <FormField label="Return to Warehouse" required>
                  <select
                    aria-label="Return to warehouse"
                    value={warehouseId}
                    onChange={(e) => setWarehouseId(e.target.value)}
                    style={{ ...inputStyle, width: 220 }}
                  >
                    <option value="">Select…</option>
                    {warehouses.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name}
                      </option>
                    ))}
                  </select>
                </FormField>
              )}
              <FormField label="Approval note">
                <input
                  aria-label="Approval note"
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
                Approve disposition: {inspection.recommendation}
              </button>
            </div>
          )}
          {!canApprove && (
            <p style={{ fontSize: 13, margin: 0 }}>
              Waiting for an approver (permission “Approve used tire inspection
              dispositions”).
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
                Cancel inspection (inspect again)
              </button>
            </div>
          )}
          <Link
            to={`/app/tires/${inspection.tire_id}`}
            style={{ fontSize: 13 }}
          >
            Open tire details
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
    <FormField label={q.label} errors={errors[k]}>
      <select
        aria-label={q.label}
        data-question={k}
        value={answers[k] ?? ""}
        onChange={(e) => onChange(k)(e.target.value)}
        style={inputStyle}
      >
        <option value="">Select…</option>
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
    <FormField label={`${label} (mm)`}>
      <NumericInput
        aria-label={`Damage ${index + 1} ${label}`}
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
        <strong style={{ fontSize: 13 }}>Damage {index + 1}</strong>
        <button type="button" className="btn-link" onClick={onRemove}>
          Remove
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
        <FormField label="Location">
          <select
            aria-label={`Damage ${index + 1} location`}
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
        <FormField label="Type">
          <select
            aria-label={`Damage ${index + 1} type`}
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
        {damage.damage_type === "PUNCTURE" && num("diameter_mm", "Diameter")}
        {damage.damage_type === "CUT" && (
          <>
            {num("length_mm", "Length")}
            {num("width_mm", "Width")}
            {num("depth_mm", "Depth")}
          </>
        )}
        <FormField label="Reaches the reinforcing structure?">
          <select
            aria-label={`Damage ${index + 1} reinforcement`}
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
        <FormField label="Overlaps a previous repair?">
          <select
            aria-label={`Damage ${index + 1} overlap`}
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
        <FormField label="Notes">
          <input
            aria-label={`Damage ${index + 1} notes`}
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
        aria-label="Evidence photos"
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
        JPG / PNG, max 3 MB each. Uploaded when the inspection is submitted.
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
            aria-label={`Evidence kind ${d.file.name}`}
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
            <option value="DAMAGE_PHOTO">Damage photo</option>
            <option value="CLOSE_UP_SCALE">Close-up with scale</option>
          </select>
          {damages.length > 0 && (
            <select
              aria-label={`Evidence damage ${d.file.name}`}
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
              <option value="">Whole tire</option>
              {damages.map((_, i) => (
                <option key={i} value={i}>
                  Damage {i + 1}
                </option>
              ))}
            </select>
          )}
          <input
            aria-label={`Evidence notes ${d.file.name}`}
            placeholder="Notes"
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
            Remove
          </button>
        </div>
      ))}
    </div>
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
