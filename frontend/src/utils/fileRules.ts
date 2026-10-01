export interface FileRule {
  /** Allowed extensions, lower case without the dot (e.g. ['pdf']). */
  extensions: string[];
  maxBytes: number;
  /** Human description used in messages (e.g. 'PDF'). */
  label: string;
}

export function formatFileSize(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Client-side pre-check only — the backend validates the file content again. */
export function fileRuleError(file: File, rule: FileRule): string | null {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  if (!rule.extensions.includes(ext)) return `Only ${rule.label} files are accepted.`;
  if (file.size > rule.maxBytes) return `The file must not be larger than ${formatFileSize(rule.maxBytes)}.`;
  return null;
}
