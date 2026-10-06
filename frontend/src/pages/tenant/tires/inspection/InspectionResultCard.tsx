import type { ReactNode } from "react";
import { StatusBadge } from "../../../../components/StatusBadge";
import { openProtectedFile } from "../../../../utils/protectedFile";
import type { ApplicationLimits, InspectionRecord } from "./inspectionTypes";
import { RECOMMENDATION_COLOR } from "./inspectionOptions";
import { statusLabel } from '../../../../i18n/statusRegistry';
import { t } from '../../../../i18n/i18n';
import { formatDateTime } from '../../../../utils/date';

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
          {t('tire.sections.recommendation')}:{" "}
          <span style={{ color }} data-recommendation>
            {inspection.recommendation}
            {inspection.recommendation_detail === "RETREAD_CANDIDATE"
              ? t('tire.sections.retreadCandidate')
              : ""}
            {inspection.additional_work === "CASING_REPAIR"
              ? t('tire.sections.casingRepair')
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
        <Row label={t('common.fields.reason')}>
          <ul style={{ margin: 0, paddingLeft: 18 }} data-reasons>
            {inspection.reasons.map((r) => (
              <li key={r}>{r}</li>
            ))}
          </ul>
        </Row>
        <Row label={t('tire.fields.minimumTreadDepth')}>
          {inspection.d_min_mm != null
            ? t('tire.help.dPullMmMm', { d_pull_mm: inspection.d_min_mm })
            : "— (incomplete)"}
        </Row>
        {inspection.remaining_tread_percent != null && (
          <Row label={t('tire.fields.remainingTread')}>
            {inspection.remaining_tread_percent}%{" "}
            <span style={{ color: "#6b7280", fontSize: 12 }}>
              {t('tire.help.treadLifeIndicatorOnlyNotSafety')}
            </span>
          </Row>
        )}
        <Row label={t('tire.fields.requiredWork')}>{inspection.result.required_work}</Row>
        <Row label={t('tire.fields.stockStatus')}>{statusLabel(inspection.result.stock_status)}</Row>
        <Row label={t('tire.fields.requirementBeforeReturningStock')}>
          {inspection.result.return_requirement}
        </Row>
        <Row label={t('tire.fields.usageRestrictions')}>
          {restrictions ? <Restrictions limits={restrictions} /> : "—"}
        </Row>
        {inspection.follow_ups.length > 0 && (
          <Row label={t('tire.fields.vehicleFollowUp')}>
            <ul style={{ margin: 0, paddingLeft: 18 }} data-follow-ups>
              {inspection.follow_ups.map((f) => (
                <li key={f}>{f}</li>
              ))}
            </ul>
          </Row>
        )}
        <Row label={t('tire.fields.evidence')}>
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
                      ? t('tire.fields.closeUpWithScale')
                      : t('tire.fields.damagePhoto')}
                    {e.notes ? ` · ${e.notes}` : ""}
                  </span>
                </div>
              ))}
        </Row>
        <Row label={t('tire.fields.inspector')}>{inspection.inspector ?? "—"}</Row>
        <Row label={t('inspection.fields.inspectionDate')}>{inspection.inspected_at ?? "—"}</Row>
        <Row label={t('tire.fields.approval')}>
          {inspection.status === "APPROVED"
            ? t('tire.help.approvedByOnDisposition', { approver: inspection.approved_by ?? "—", date: inspection.approved_at ? formatDateTime(inspection.approved_at) : "—", final_disposition: inspection.final_disposition })
            : inspection.status === "CANCELLED"
              ? t('tire.fields.cancelledValue', { value: inspection.cancelled_at ?? "" })
              : t('tire.help.waitingApprovalTireKeepsCurrentStatus')}
        </Row>
        <Row label={t('tire.fields.ruleProfile')}>
          {inspection.thresholds
            ? `${inspection.thresholds.name} · version ${inspection.rule_profile_version} · D_service ${inspection.thresholds.d_service_mm} mm · D_pull ${inspection.thresholds.d_pull_mm} mm`
            : t('tire.help.noneNoActiveProfileTireCategory')}
        </Row>
      </dl>
      {actions && <div style={{ marginTop: 14 }}>{actions}</div>}
    </section>
  );
}

function Restrictions({ limits }: { limits: ApplicationLimits }) {
  const parts = [
    limits.positions?.length
      ? t('tire.fields.positionsValue', { value: limits.positions.join(", ") })
      : null,
    limits.max_load_kg != null && limits.max_load_kg !== ""
      ? t('tire.help.maxLoadMaxLoadKgKg', { max_load_kg: limits.max_load_kg })
      : null,
    limits.max_speed_kmh != null && limits.max_speed_kmh !== ""
      ? t('tire.help.maxSpeedMaxSpeedKmhKm', { max_speed_kmh: limits.max_speed_kmh })
      : null,
    limits.operations?.length
      ? t('tire.fields.operationValue', { value: limits.operations.join(", ") })
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
