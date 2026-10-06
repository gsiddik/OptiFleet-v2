import { inputStyle } from "../../../../components/FormField";
import { InfoTip } from "../../../../components/InfoTip";
import type { WorkflowStatusDef } from "../../../../types";
import { labelOf, type EditableTransition } from "./workflowGraph";
import { automatedActionLabel } from "../../../../i18n/workflowAutomatedActions";
import { t } from '../../../../i18n/i18n';

/**
 * The selected transition (arrow): From / To (the same change as dragging the arrow's ends),
 * action name, required permission and automated actions. Conditions and approval rules already
 * on a transition are shown and kept as they are.
 */
export function TransitionConfigurationPanel({
  transition,
  statuses,
  targets,
  permissions,
  actions,
  readOnly,
  onChange,
  onDelete,
}: {
  transition: EditableTransition;
  statuses: WorkflowStatusDef[];
  targets: string[] | null;
  permissions: Array<{ name: string; description: string | null }> | null;
  actions: string[];
  readOnly: boolean;
  onChange: (transition: EditableTransition) => void;
  onDelete: () => void;
}) {
  const automated = (transition.automated_actions ?? []).map((a) =>
    typeof a === "string" ? a : a.action_code,
  );
  const conditionRules =
    (transition.condition_set as { rules?: unknown[] } | null | undefined)
      ?.rules?.length ?? 0;
  const permission = transition.required_permission ?? "";

  return (
    <div
      data-transition-panel={`${transition.from_status}>${transition.to_status}`}
    >
      <h3 style={{ fontSize: 15, margin: "0 0 10px" }}>{t('configuration.sections.transition')}</h3>
      <label style={labelStyle}>
        {t('common.fields.from')}
        <select
          value={transition.from_status}
          onChange={(e) =>
            onChange({ ...transition, from_status: e.target.value })
          }
          style={inputStyle}
          disabled={readOnly}
          aria-label={t('configuration.fields.transitionFrom')}
        >
          {statuses.map((s) => (
            <option key={s.code} value={s.code}>
              {labelOf(s)}
            </option>
          ))}
        </select>
      </label>
      <label style={labelStyle}>
        {t('common.fields.to')}
        <select
          value={transition.to_status}
          onChange={(e) =>
            onChange({ ...transition, to_status: e.target.value })
          }
          style={inputStyle}
          disabled={readOnly}
          aria-label={t('configuration.fields.transitionTo')}
        >
          {statuses.map((s) => (
            <option
              key={s.code}
              value={s.code}
              disabled={targets !== null && !targets.includes(s.code)}
            >
              {labelOf(s)}
              {targets !== null && !targets.includes(s.code)
                ? t('configuration.fields.noActionLeadsHere')
                : ""}
            </option>
          ))}
        </select>
      </label>
      <label style={labelStyle}>
        {t('configuration.fields.actionName')}
        <input
          value={transition.action_label ?? ""}
          onChange={(e) =>
            onChange({ ...transition, action_label: e.target.value })
          }
          style={inputStyle}
          disabled={readOnly}
          aria-label={t('configuration.fields.actionName')}
        />
      </label>
      <label style={labelStyle}>
        {t('configuration.fields.actionCode')}
        <input
          value={transition.action_code}
          onChange={(e) =>
            onChange({
              ...transition,
              action_code: e.target.value
                .replace(/[^a-z0-9_]/gi, "_")
                .toLowerCase(),
            })
          }
          style={inputStyle}
          disabled={readOnly}
          aria-label={t('configuration.fields.actionCode')}
        />
      </label>
      <div style={{ ...labelStyle, display: "flex", alignItems: "center" }}>
        {t('configuration.fields.requiredPermission')}
        <InfoTip label={t('configuration.fields.requiredPermission')}>
          {t('configuration.tooltips.onlyUsersWhoseRoleGrantsPermission')}
        </InfoTip>
      </div>
      {permissions ? (
        <select
          value={permission}
          onChange={(e) =>
            onChange({
              ...transition,
              required_permission: e.target.value || null,
            })
          }
          style={{ ...inputStyle, marginBottom: 8 }}
          disabled={readOnly}
          aria-label={t('configuration.fields.requiredPermission')}
        >
          <option value="">{t('configuration.fields.none')}</option>
          {permission && !permissions.some((p) => p.name === permission) && (
            <option value={permission}>{permission}</option>
          )}
          {permissions.map((p) => (
            <option key={p.name} value={p.name}>
              {p.description ? `${p.description} (${p.name})` : p.name}
            </option>
          ))}
        </select>
      ) : (
        <input
          value={permission}
          onChange={(e) =>
            onChange({
              ...transition,
              required_permission: e.target.value || null,
            })
          }
          style={{ ...inputStyle, marginBottom: 8 }}
          disabled={readOnly}
          aria-label={t('configuration.fields.requiredPermission')}
        />
      )}
      <div style={{ ...labelStyle, marginTop: 4 }}>{t('configuration.fields.automatedActions')}</div>
      <div style={{ display: "grid", gap: 2, fontSize: 12, marginBottom: 8 }}>
        {actions.map((code) => (
          <label key={code}>
            <input
              type="checkbox"
              checked={automated.includes(code)}
              disabled={readOnly}
              onChange={(e) =>
                onChange({
                  ...transition,
                  automated_actions: e.target.checked
                    ? [...(transition.automated_actions ?? []), code]
                    : (transition.automated_actions ?? []).filter(
                        (a) =>
                          (typeof a === "string" ? a : a.action_code) !== code,
                      ),
                })
              }
            />{" "}
            {automatedActionLabel(code)}
          </label>
        ))}
      </div>
      {(conditionRules > 0 || transition.approval_rule) && (
        <p
          style={{
            fontSize: 12,
            color: "#475569",
            background: "#f8fafc",
            padding: 8,
            borderRadius: 6,
          }}
        >
          {conditionRules > 0 && <>{t('configuration.help.conditionConditionRulesRuleS', { count: conditionRules })} </>}
          {transition.approval_rule && (
            <>
              {t('tire.fields.approval')}: {transition.approval_rule.type.toLowerCase()},{" "}
              {t('configuration.help.stepsCountStepS', { count: transition.approval_rule.steps.length })}
            </>
          )}{" "}
          {t('configuration.help.keptAsConfigured')}
        </p>
      )}
      {!readOnly && (
        <button
          type="button"
          className="btn-secondary"
          style={{ color: "#b91c1c", marginTop: 8 }}
          onClick={onDelete}
        >
          {t('configuration.actions.deleteTransition')}
        </button>
      )}
    </div>
  );
}

const labelStyle = {
  display: "block",
  fontSize: 12,
  fontWeight: 600,
  color: "#374151",
  marginBottom: 8,
} as const;
