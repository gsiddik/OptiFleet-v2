import { useEffect, useState } from "react";
import {
  apiClient,
  extractApiError,
  type ApiErrorShape,
} from "../../../../api/client";
import { useAuth } from "../../../../auth/AuthContext";
import { FormField, inputStyle } from "../../../../components/FormField";
import { Modal } from "../../../../components/Modal";
import { NumericInput } from "../../../../components/NumericInput";
import {
  EmptyState,
  ErrorState,
  LoadingState,
} from "../../../../components/States";
import { StatusBadge } from "../../../../components/StatusBadge";
import { CATEGORY_LABELS, LOCATIONS } from "./inspectionOptions";
import type {
  ApplicationLimits,
  RepairLimits,
  TireCategory,
} from "./inspectionTypes";
import { t } from '../../../../i18n/i18n';

interface Profile {
  id: string;
  name: string;
  tire_category: TireCategory;
  product_id: string | null;
  product: { id: string; name: string; brand: string | null } | null;
  application: string | null;
  d_service_mm: string;
  d_pull_mm: string;
  a_max_months: number;
  a_retread_max_months: number;
  n_retread_max: number;
  repair_limits: RepairLimits;
  application_limits: ApplicationLimits;
  version: number;
  status: string;
}

/**
 * Inspection Rules: the thresholds the used tire decision engine applies, per tire category
 * (+ optional tire product = brand/model, + optional application). No value is built into the
 * application — each company sets its own. Every change creates a new version; inspections keep
 * the version they were decided with.
 */
export function TireRuleProfilesPage() {
  const { hasPermission } = useAuth();
  const canManage = hasPermission("tire_rule_profile.manage");
  const [profiles, setProfiles] = useState<Profile[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<Profile | "new" | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    apiClient
      .get("/app/tire-rule-profiles")
      .then((res) => setProfiles(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [reloadKey]);

  async function deactivate(p: Profile) {
    try {
      await apiClient.post(`/app/tire-rule-profiles/${p.id}/deactivate`);
      setReloadKey((k) => k + 1);
    } catch (e) {
      setError(extractApiError(e).message);
    }
  }

  return (
    <div>
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          gap: 10,
          flexWrap: "wrap",
          marginBottom: 6,
        }}
      >
        <h1 style={{ fontSize: 22, margin: 0 }}>{t('tire.titles.inspectionRules')}</h1>
        {canManage && (
          <button
            type="button"
            className="btn-primary"
            onClick={() => setEditing("new")}
          >
            {t('tire.actions.newRuleProfile')}
          </button>
        )}
      </div>
      <p style={{ fontSize: 13, color: "#6b7280", marginTop: 0 }}>
        {t('tire.help.thresholdsUsedTireInspectionPerTire')}
      </p>
      {error && <ErrorState message={error} />}
      {!profiles && !error && <LoadingState />}
      {profiles && profiles.length === 0 && (
        <EmptyState label={t('tire.empty.noRuleProfilesYetUsedTire')} />
      )}
      {profiles && profiles.length > 0 && (
        <div className="card" style={{ overflowX: "auto" }}>
          <table
            style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}
            data-rule-profiles
          >
            <thead>
              <tr>
                {[
                  t('common.fields.category'),
                  t('common.fields.name'),
                  t('inventory.fields.tireProduct'),
                  t('tire.fields.application'),
                  "D_service",
                  "D_pull",
                  "A_max",
                  "A_retread_max",
                  "N_retread_max",
                  t('configuration.fields.version'),
                  t('common.fields.status'),
                  "",
                ].map((h) => (
                  <th
                    key={h}
                    style={{
                      textAlign: "left",
                      padding: "8px 10px",
                      borderBottom: "1px solid #e5e7eb",
                      whiteSpace: "nowrap",
                    }}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {profiles.map((p) => (
                <tr key={p.id} data-profile={p.tire_category}>
                  <td style={td}>{CATEGORY_LABELS[p.tire_category]}</td>
                  <td style={td}>{p.name}</td>
                  <td style={td}>
                    {p.product
                      ? `${p.product.brand ?? ""} ${p.product.name}`.trim()
                      : t('tire.help.all')}
                  </td>
                  <td style={td}>{p.application ?? t('tire.help.general')}</td>
                  <td style={td}>{p.d_service_mm} {t('tire.help.mm')}</td>
                  <td style={td}>{p.d_pull_mm} {t('tire.help.mm')}</td>
                  <td style={td}>{p.a_max_months} mo</td>
                  <td style={td}>{p.a_retread_max_months} mo</td>
                  <td style={td}>{p.n_retread_max}</td>
                  <td style={td}>v{p.version}</td>
                  <td style={td}>
                    <StatusBadge status={p.status} />
                  </td>
                  <td style={{ ...td, display: "flex", gap: 10 }}>
                    {canManage && (
                      <>
                        <button
                          type="button"
                          className="btn-link"
                          onClick={() => setEditing(p)}
                        >
                          {t('common.actions.edit')}
                        </button>
                        <button
                          type="button"
                          className="btn-link"
                          style={{ color: "#b91c1c" }}
                          onClick={() => deactivate(p)}
                        >
                          {t('common.actions.deactivate')}
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {editing && (
        <ProfileForm
          profile={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

const td = {
  padding: "8px 10px",
  borderBottom: "1px solid #f3f4f6",
  whiteSpace: "nowrap" as const,
};
const str = (v: string | number | null | undefined) =>
  v == null ? "" : String(v);

function ProfileForm({
  profile,
  onClose,
  onSaved,
}: {
  profile: Profile | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [products, setProducts] = useState<
    { id: string; name: string; brand: string | null }[]
  >([]);
  const [form, setForm] = useState({
    name: profile?.name ?? "",
    tire_category: profile?.tire_category ?? "TRUCK_BUS",
    product_id: profile?.product_id ?? "",
    application: profile?.application ?? "",
    d_service_mm: str(profile?.d_service_mm),
    d_pull_mm: str(profile?.d_pull_mm),
    a_max_months: str(profile?.a_max_months),
    a_retread_max_months: str(profile?.a_retread_max_months),
    n_retread_max: str(profile?.n_retread_max),
  });
  const [limits, setLimits] = useState({
    allowed_locations: profile?.repair_limits.allowed_locations ?? ["TREAD"],
    max_puncture_diameter_mm: str(
      profile?.repair_limits.max_puncture_diameter_mm,
    ),
    max_cut_length_mm: str(profile?.repair_limits.max_cut_length_mm),
    max_cut_width_mm: str(profile?.repair_limits.max_cut_width_mm),
    max_cut_depth_mm: str(profile?.repair_limits.max_cut_depth_mm),
    max_repairs: str(profile?.repair_limits.max_repairs),
    allow_overlap_previous_repair:
      profile?.repair_limits.allow_overlap_previous_repair ?? false,
    allow_reinforcement_damage:
      profile?.repair_limits.allow_reinforcement_damage ?? false,
  });
  const [usage, setUsage] = useState({
    positions: (profile?.application_limits.positions ?? []).join(", "),
    max_load_kg: str(profile?.application_limits.max_load_kg),
    max_speed_kmh: str(profile?.application_limits.max_speed_kmh),
    operations: (profile?.application_limits.operations ?? []).join(", "),
    notes: profile?.application_limits.notes ?? "",
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    apiClient
      .get("/app/tire-products", { params: { per_page: 100 } })
      .then((res) => setProducts(res.data.data))
      .catch(() => setProducts([]));
  }, []);

  const list = (v: string) =>
    v
      .split(",")
      .map((x) => x.trim())
      .filter(Boolean);
  const nullable = (v: string) => (v === "" ? null : v);

  async function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    const body = {
      ...form,
      product_id: form.product_id || null,
      application: form.application || null,
      repair_limits: {
        ...limits,
        max_puncture_diameter_mm: nullable(limits.max_puncture_diameter_mm),
        max_cut_length_mm: nullable(limits.max_cut_length_mm),
        max_cut_width_mm: nullable(limits.max_cut_width_mm),
        max_cut_depth_mm: nullable(limits.max_cut_depth_mm),
        max_repairs: nullable(limits.max_repairs),
      },
      application_limits: {
        positions: list(usage.positions),
        max_load_kg: nullable(usage.max_load_kg),
        max_speed_kmh: nullable(usage.max_speed_kmh),
        operations: list(usage.operations),
        notes: usage.notes || null,
      },
    };
    try {
      if (profile)
        await apiClient.put(`/app/tire-rule-profiles/${profile.id}`, body);
      else await apiClient.post("/app/tire-rule-profiles", body);
      onSaved();
    } catch (e) {
      const api: ApiErrorShape = extractApiError(e);
      setErrors(api.errors ?? {});
      if (!api.errors) setError(api.message);
    } finally {
      setBusy(false);
    }
  }

  const field = (
    key: keyof typeof form,
    label: string,
    required = true,
    step?: string,
  ) => (
    <FormField label={label} errors={errors[key]} required={required}>
      <NumericInput
        aria-label={label}
        step={step}
        value={form[key]}
        onChange={(e) => setForm({ ...form, [key]: e.target.value })}
        style={inputStyle}
      />
    </FormField>
  );
  const limit = (
    key:
      | "max_puncture_diameter_mm"
      | "max_cut_length_mm"
      | "max_cut_width_mm"
      | "max_cut_depth_mm"
      | "max_repairs",
    label: string,
  ) => (
    <FormField label={label} errors={errors[`repair_limits.${key}`]}>
      <NumericInput
        aria-label={label}
        step="0.1"
        value={limits[key]}
        onChange={(e) => setLimits({ ...limits, [key]: e.target.value })}
        style={inputStyle}
      />
    </FormField>
  );

  return (
    <Modal
      open
      title={
        profile
          ? t('tire.modals.editRuleProfileVVersionV', { version: profile.version, value: profile.version + 1 })
          : t('tire.actions.newRuleProfile')
      }
      onClose={onClose}
      width={760}
    >
      {error && <ErrorState message={error} />}
      <div
        style={{
          display: "grid",
          gridTemplateColumns:
            "repeat(auto-fit, minmax(min(100%, 210px), 1fr))",
          gap: "0 14px",
        }}
      >
        <FormField label={t('common.fields.name')} errors={errors.name} required>
          <input
            aria-label={t('common.fields.name')}
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            style={inputStyle}
          />
        </FormField>
        <FormField label={t('tire.fields.tireCategory')} errors={errors.tire_category} required>
          <select
            aria-label={t('tire.fields.tireCategory')}
            value={form.tire_category}
            onChange={(e) =>
              setForm({
                ...form,
                tire_category: e.target.value as TireCategory,
              })
            }
            style={inputStyle}
          >
            {Object.entries(CATEGORY_LABELS).map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </select>
        </FormField>
        <FormField
          label={t('tire.fields.tireProductBrandModel')}
          errors={errors.product_id}
        >
          <select
            aria-label={t('inventory.fields.tireProduct')}
            value={form.product_id}
            onChange={(e) => setForm({ ...form, product_id: e.target.value })}
            style={inputStyle}
          >
            <option value="">{t('tire.filters.allProductsCategory')}</option>
            {products.map((p) => (
              <option key={p.id} value={p.id}>
                {p.brand ? `${p.brand} — ` : ""}
                {p.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t('tire.fields.application')} errors={errors.application}>
          <input
            aria-label={t('tire.fields.application')}
            placeholder={t('tire.help.general')}
            value={form.application}
            onChange={(e) => setForm({ ...form, application: e.target.value })}
            style={inputStyle}
          />
        </FormField>
        {field(
          "d_service_mm",
          t('tire.help.dServiceMinimumServiceTreadMm'),
          true,
          "0.1",
        )}
        {field(
          "d_pull_mm",
          t('tire.help.dPullPlannedRemovalTreadMm'),
          true,
          "0.1",
        )}
        {field("a_max_months", t('tire.help.maxMaximumTireAgeServiceMonths'))}
        {field(
          "a_retread_max_months",
          t('tire.help.retreadMaxMaximumCasingAgeRetread'),
        )}
        {field("n_retread_max", t('tire.fields.nRetreadMaxMaximumRetreadCount'))}
      </div>
      <h4 style={{ margin: "10px 0 6px", fontSize: 14 }}>{t('tire.sections.repairLimits')}</h4>
      <div
        style={{
          display: "flex",
          gap: 12,
          flexWrap: "wrap",
          fontSize: 13,
          marginBottom: 8,
        }}
        data-allowed-locations
      >
        {LOCATIONS.map((l) => (
          <label
            key={l.value}
            style={{ display: "flex", gap: 4, alignItems: "center" }}
          >
            <input
              type="checkbox"
              checked={limits.allowed_locations.includes(l.value)}
              onChange={(e) =>
                setLimits({
                  ...limits,
                  allowed_locations: e.target.checked
                    ? [...limits.allowed_locations, l.value]
                    : limits.allowed_locations.filter((x) => x !== l.value),
                })
              }
            />
            Repairs allowed on {l.label}
          </label>
        ))}
      </div>
      <div
        style={{
          display: "grid",
          gridTemplateColumns:
            "repeat(auto-fit, minmax(min(100%, 170px), 1fr))",
          gap: "0 14px",
        }}
      >
        {limit("max_puncture_diameter_mm", t('tire.fields.maxPunctureDiameterMm'))}
        {limit("max_cut_length_mm", t('tire.fields.maxCutLengthMm'))}
        {limit("max_cut_width_mm", t('tire.fields.maxCutWidthMm'))}
        {limit("max_cut_depth_mm", t('tire.fields.maxCutDepthMm'))}
        {limit("max_repairs", t('tire.fields.maxNumberOfRepairs'))}
      </div>
      <div style={{ display: "flex", gap: 16, flexWrap: "wrap", fontSize: 13 }}>
        <label style={{ display: "flex", gap: 4, alignItems: "center" }}>
          <input
            type="checkbox"
            checked={limits.allow_overlap_previous_repair}
            onChange={(e) =>
              setLimits({
                ...limits,
                allow_overlap_previous_repair: e.target.checked,
              })
            }
          />
          {t('tire.fields.allowOverlapPreviousRepair')}
        </label>
        <label style={{ display: "flex", gap: 4, alignItems: "center" }}>
          <input
            type="checkbox"
            checked={limits.allow_reinforcement_damage}
            onChange={(e) =>
              setLimits({
                ...limits,
                allow_reinforcement_damage: e.target.checked,
              })
            }
          />
          {t('tire.fields.allowRepairDamageReachingReinforcingStructure')}
        </label>
      </div>
      <h4 style={{ margin: "12px 0 6px", fontSize: 14 }}>
        {t('tire.sections.applicationUsageRestrictionsShownInspectionResult')}
      </h4>
      <div
        style={{
          display: "grid",
          gridTemplateColumns:
            "repeat(auto-fit, minmax(min(100%, 200px), 1fr))",
          gap: "0 14px",
        }}
      >
        <FormField label={t('tire.fields.positionsCommaSeparated')}>
          <input
            aria-label={t('tire.fields.positions')}
            value={usage.positions}
            onChange={(e) => setUsage({ ...usage, positions: e.target.value })}
            style={inputStyle}
          />
        </FormField>
        <FormField label={t('tire.fields.maxLoadKg')}>
          <NumericInput
            aria-label={t('tire.fields.maxLoad')}
            value={usage.max_load_kg}
            onChange={(e) =>
              setUsage({ ...usage, max_load_kg: e.target.value })
            }
            style={inputStyle}
          />
        </FormField>
        <FormField label={t('tire.fields.maxSpeedKmH')}>
          <NumericInput
            aria-label={t('tire.fields.maxSpeed')}
            value={usage.max_speed_kmh}
            onChange={(e) =>
              setUsage({ ...usage, max_speed_kmh: e.target.value })
            }
            style={inputStyle}
          />
        </FormField>
        <FormField label={t('tire.fields.operationApplicationCommaSeparated')}>
          <input
            aria-label={t('tire.fields.operations')}
            value={usage.operations}
            onChange={(e) => setUsage({ ...usage, operations: e.target.value })}
            style={inputStyle}
          />
        </FormField>
      </div>
      <FormField label={t('common.fields.notes')}>
        <input
          aria-label={t('tire.fields.usageNotes')}
          value={usage.notes}
          onChange={(e) => setUsage({ ...usage, notes: e.target.value })}
          style={inputStyle}
        />
      </FormField>
      <div
        style={{
          display: "flex",
          justifyContent: "flex-end",
          gap: 8,
          marginTop: 12,
        }}
      >
        <button type="button" className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button
          type="button"
          className="btn-primary"
          disabled={busy}
          onClick={save}
        >
          {busy ? t('common.actions.saving') : t('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
