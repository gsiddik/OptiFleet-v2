import type { ReactNode } from "react";
import { StatusBadge } from "../../../../components/StatusBadge";
import { openProtectedFile } from "../../../../utils/protectedFile";
import type { ApplicationLimits, InspectionRecord } from "./inspectionTypes";
import { RECOMMENDATION_COLOR } from "./inspectionOptions";
import { statusLabel } from '../../../../i18n/statusRegistry';

/**
 * Inspection result summary: recommendation and why, minimum tread, required work, stock status,
 * what is needed before the tire may return to stock, usage restrictions, evidence, inspector and
 * approval. Every value comes from the backend decision.
 */
export function InspectionResultCard({
  inspection,
  actions,
}: {
  inspection: InspectionRecord;
  actions?: ReactNode;
}) {
  const color = RECOMMENDATION_COLOR[inspection.recommendation] ?? "#374151";
  const restrictions = Array.isArray(inspection.result.usage_restrictions)
    ? null
    : (inspection.result.usage_restrictions as ApplicationLimits);

  return (
    <section
      className="card"
      data-inspection-result={inspection.recommendation}
      style={{ borderLeft: `5px solid ${color}` }}
    >
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          gap: 10,
          flexWrap: "wrap",
          marginBottom: 10,
        }}
      >
        <h3 style={{ margin: 0, fontSize: 16 }}>
          Recommendation:{" "}
          <span style={{ color }} data-recommendation>
            {inspection.recommendation}
            {inspection.recommendation_detail === "RETREAD_CANDIDATE"
              ? " — Retread Candidate"
              : ""}
            {inspection.additional_work === "CASING_REPAIR"
              ? " + Casing Repair"
              : ""}
          </span>
        </h3>
        <StatusBadge status={inspection.status} />
      </div>
      <dl
        style={{
          display: "grid",
          gridTemplateColumns: "minmax(180px, max-content) 1fr",
          gap: "8px 14px",
          fontSize: 14,
          margin: 0,
        }}
      >
        <Row label="Reason">
          <ul style={{ margin: 0, paddingLeft: 18 }} data-reasons>
            {inspection.reasons.map((r) => (
              <li key={r}>{r}</li>
            ))}
          </ul>
        </Row>
        <Row label="Minimum Tread Depth">
          {inspection.d_min_mm != null
            ? `${inspection.d_min_mm} mm`
            : "— (incomplete)"}
        </Row>
        {inspection.remaining_tread_percent != null && (
          <Row label="Remaining Tread">
            {inspection.remaining_tread_percent}%{" "}
            <span style={{ color: "#6b7280", fontSize: 12 }}>
              (tread life indicator only — not a safety score or remaining KM)
            </span>
          </Row>
        )}
        <Row label="Required Work">{inspection.result.required_work}</Row>
        <Row label="Stock Status">{statusLabel(inspection.result.stock_status)}</Row>
        <Row label="Requirement Before Returning to Stock">
          {inspection.result.return_requirement}
        </Row>
        <Row label="Usage Restrictions">
          {restrictions ? <Restrictions limits={restrictions} /> : "—"}
        </Row>
        {inspection.follow_ups.length > 0 && (
          <Row label="Vehicle Follow-up">
            <ul style={{ margin: 0, paddingLeft: 18 }} data-follow-ups>
              {inspection.follow_ups.map((f) => (
                <li key={f}>{f}</li>
              ))}
            </ul>
          </Row>
        )}
        <Row label="Evidence">
          {inspection.evidence.length === 0
            ? "—"
            : inspection.evidence.map((e) => (
                <div key={e.id}>
                  <button
                    type="button"
                    className="btn-link"
                    onClick={() =>
                      openProtectedFile(
                        `/app/tire-used-inspections/${inspection.id}/evidence/${e.id}`,
                      )
                    }
                  >
                    {e.original_filename}
                  </button>{" "}
                  <span style={{ color: "#6b7280", fontSize: 12 }}>
                    {e.kind === "CLOSE_UP_SCALE"
                      ? "close-up with scale"
                      : "damage photo"}
                    {e.notes ? ` · ${e.notes}` : ""}
                  </span>
                </div>
              ))}
        </Row>
        <Row label="Inspector">{inspection.inspector ?? "—"}</Row>
        <Row label="Inspection Date">{inspection.inspected_at ?? "—"}</Row>
        <Row label="Approval">
          {inspection.status === "APPROVED"
            ? `Approved by ${inspection.approved_by ?? "—"} on ${inspection.approved_at ?? "—"} — disposition ${inspection.final_disposition}`
            : inspection.status === "CANCELLED"
              ? `Cancelled ${inspection.cancelled_at ?? ""}`
              : "Waiting for approval — the tire keeps its current status until then."}
        </Row>
        <Row label="Rule Profile">
          {inspection.thresholds
            ? `${inspection.thresholds.name} · version ${inspection.rule_profile_version} · D_service ${inspection.thresholds.d_service_mm} mm · D_pull ${inspection.thresholds.d_pull_mm} mm`
            : "None — no active profile for this tire category"}
        </Row>
      </dl>
      {actions && <div style={{ marginTop: 14 }}>{actions}</div>}
    </section>
  );
}

function Restrictions({ limits }: { limits: ApplicationLimits }) {
  const parts = [
    limits.positions?.length
      ? `Positions: ${limits.positions.join(", ")}`
      : null,
    limits.max_load_kg != null && limits.max_load_kg !== ""
      ? `Max load ${limits.max_load_kg} kg`
      : null,
    limits.max_speed_kmh != null && limits.max_speed_kmh !== ""
      ? `Max speed ${limits.max_speed_kmh} km/h`
      : null,
    limits.operations?.length
      ? `Operation: ${limits.operations.join(", ")}`
      : null,
    limits.notes || null,
  ].filter(Boolean);
  return <>{parts.length ? parts.join(" · ") : "—"}</>;
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt style={{ color: "#6b7280" }}>{label}</dt>
      <dd style={{ margin: 0 }}>{children}</dd>
    </>
  );
}
