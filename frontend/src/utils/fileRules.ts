import { t } from '../i18n/i18n';
export interface FileRule {
  /** Allowed extensions, lower case without the dot (e.g. ['pdf']). */
  extensions: string[];
  maxBytes: number;
  /** Human description used in messages (e.g. 'PDF'), in English. */
  label: string;
  /** Translation key for `label` when it holds words (e.g. "… or PDF"); a bare format name needs none. */
  labelKey?: string;
}

export function formatFileSize(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** The rule's description in the current language. */
export function fileRuleLabel(rule: FileRule): string {
  return rule.labelKey ? t(rule.labelKey) : rule.label;
}

/** Client-side pre-check only — the backend validates the file content again. */
export function fileRuleError(file: File, rule: FileRule): string | null {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  if (!rule.extensions.includes(ext)) return t('common.help.onlyLabelFilesAccepted', { label: fileRuleLabel(rule) });
  if (file.size > rule.maxBytes) return t('common.validation.fileMustNotLargerThanMax', { maxBytes: formatFileSize(rule.maxBytes) });
  return null;
}

/** Vendor invoice document: PDF only, 10 MB (validated again on the server). */
export const INVOICE_PDF_RULE: FileRule = { extensions: ['pdf'], maxBytes: 10 * 1024 * 1024, label: 'PDF' };

/** Vendor invoice payment proof: image or PDF, 10 MB (validated again on the server). */
export const PAYMENT_PROOF_RULE: FileRule = { extensions: ['jpg', 'jpeg', 'png', 'pdf'], maxBytes: 10 * 1024 * 1024, label: 'JPG, JPEG, PNG or PDF', labelKey: 'common.fields.jpgJpegPngPdf' };
