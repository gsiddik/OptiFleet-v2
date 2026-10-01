import { useRef, useState } from 'react';
import { fileRuleError, formatFileSize, type FileRule } from '../utils/fileRules';

/**
 * File picker for a form that is saved later: choose, replace or remove the file before
 * submitting; the parent sends `file` with its own Save/Submit request. An `existing` document
 * (already stored) can be shown instead, with a View action.
 */
export function FileUploadField({
  file,
  onChange,
  rule,
  ariaLabel,
  existing,
  disabled = false,
}: {
  file: File | null;
  onChange: (file: File | null) => void;
  rule: FileRule;
  ariaLabel: string;
  existing?: { name: string; onView: () => void } | null;
  disabled?: boolean;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [error, setError] = useState<string | null>(null);
  const accept = rule.extensions.map((e) => `.${e}`).join(',');

  function pick(selected: File | undefined) {
    if (!selected) return;
    const problem = fileRuleError(selected, rule);
    setError(problem);
    if (!problem) onChange(selected);
    if (inputRef.current) inputRef.current.value = '';
  }

  const box = { display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' as const, padding: '8px 10px', border: '1px dashed #cbd5e1', borderRadius: 6, background: '#f9fafb', fontSize: 13 };

  return (
    <div>
      <input ref={inputRef} type="file" accept={accept} aria-label={ariaLabel} style={{ display: 'none' }} onChange={(e) => pick(e.target.files?.[0])} disabled={disabled} />
      {file ? (
        <div style={box}>
          <span>📎 {file.name}</span>
          <span style={{ color: '#6b7280' }}>{formatFileSize(file.size)}</span>
          {!disabled && (
            <>
              <button type="button" className="btn-link" onClick={() => inputRef.current?.click()}>
                Replace
              </button>
              <button type="button" className="btn-link" style={{ color: '#b91c1c' }} onClick={() => onChange(null)}>
                Remove
              </button>
            </>
          )}
        </div>
      ) : existing ? (
        <div style={box}>
          <span>📎 {existing.name}</span>
          <button type="button" className="btn-link" onClick={existing.onView}>
            View
          </button>
        </div>
      ) : (
        <div style={box}>
          <button type="button" className="btn-secondary" onClick={() => inputRef.current?.click()} disabled={disabled}>
            Choose file
          </button>
          <span style={{ color: '#6b7280' }}>
            {rule.label}, max {formatFileSize(rule.maxBytes)}
          </span>
        </div>
      )}
      {error && <div style={{ color: '#b91c1c', fontSize: 12, marginTop: 4 }}>{error}</div>}
    </div>
  );
}
