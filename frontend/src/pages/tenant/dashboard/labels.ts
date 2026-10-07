import { t } from '../../../i18n/i18n';
import { statusLabel } from '../../../i18n/statusRegistry';

/** Labels for codes the shared status registry does not cover. */
const EXTRA: Record<string, string> = {
  IN_USE: 'dashboard.labels.inUse',
  MINOR: 'dashboard.labels.severityMinor',
  MAJOR: 'dashboard.labels.severityMajor',
  IMMOBILIZED: 'dashboard.labels.severityImmobilized',
  PREVENTIVE: 'dashboard.labels.typePreventive',
  CORRECTIVE: 'dashboard.labels.typeCorrective',
  INSPECTION: 'dashboard.labels.typeInspection',
  CAMPAIGN: 'dashboard.labels.typeCampaign',
  OUT: 'dashboard.labels.stockOut',
  LOW: 'dashboard.labels.stockLow',
  NORMAL: 'dashboard.labels.stockNormal',
  RETREAD: 'dashboard.labels.retread',
  REPAIR: 'dashboard.labels.repair',
  SCRAP: 'dashboard.labels.scrap',
  REUSE: 'dashboard.labels.reuse',
  HOLD: 'dashboard.labels.hold',
};

export function codeLabel(code: string, context?: 'severity' | 'type' | 'stock' | 'tireRecommendation'): string {
  if (context === 'type' && code === 'BREAKDOWN') return t('dashboard.labels.typeBreakdown');
  if (code === 'ISSUED') return statusLabel(code, 'document');
  if (EXTRA[code] && (context || !['RETREAD', 'REPAIR', 'REUSE', 'HOLD'].includes(code))) return t(EXTRA[code]);
  return statusLabel(code);
}

const DOC_TYPES: Record<string, string> = {
  REGISTRATION: 'tire.fields.registration',
  INSPECTION_CERTIFICATE: 'vehicle.documentType.inspectionCertificate',
  INSURANCE: 'vehicle.documentType.insurance',
  PERMIT: 'vehicle.documentType.permit',
  VEHICLE_TAX: 'vehicle.documentType.vehicleTax',
  WARRANTY: 'vehicle.documentType.warranty',
  OTHER: 'tire.fields.other',
};

export function documentTypeLabel(code: string): string {
  return DOC_TYPES[code] ? t(DOC_TYPES[code]) : code;
}

/** Column header as a translation key: resolved when the table renders, so module-level column lists follow the UI language. */
export const col = (key: string) => `dashboard.columns.${key}`;

/** Header text: translation keys are translated at render time, anything else is shown as given. */
export function headerText(label: string): string {
  return label.startsWith('dashboard.') ? t(label) : label;
}

export const widgetKey = (id: string) => id.replace('-', '').toLowerCase();

export function widgetTitle(id: string): string {
  return t(`dashboard.widgets.${widgetKey(id)}.title`);
}
