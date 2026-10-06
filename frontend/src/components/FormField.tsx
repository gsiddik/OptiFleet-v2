import { cloneElement, isValidElement, type CSSProperties, type ReactNode } from 'react';
import { InfoTip } from './InfoTip';
import { t } from '../i18n/i18n';

function labelElement(label: string, required: boolean | undefined, marginBottom: number) {
  return (
    <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom, color: '#374151' }}>
      {label}
      {required && (
        <>
          <span aria-hidden="true" style={{ color: '#dc2626', marginLeft: 3 }}>
            *
          </span>
          <span className="sr-only"> {t('common.fields.required')}</span>
        </>
      )}
    </label>
  );
}

export function FormField({
  label,
  errors,
  required,
  hint,
  children,
}: {
  label: string;
  errors?: string[];
  required?: boolean;
  /** Optional explanation, shown in an accessible tooltip next to the label. */
  hint?: ReactNode;
  children: ReactNode;
}) {
  const content =
    required && isValidElement<{ 'aria-required'?: boolean; required?: boolean }>(children)
      ? cloneElement(children, { 'aria-required': true, required: true })
      : children;

  return (
    <div style={{ marginBottom: 14 }}>
      {hint ? (
        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 4 }}>
          {labelElement(label, required, 0)}
          <InfoTip label={label}>{hint}</InfoTip>
        </div>
      ) : (
        labelElement(label, required, 4)
      )}
      {content}
      {errors?.map((err) => (
        <div key={err} style={{ color: '#b91c1c', fontSize: 12, marginTop: 4 }}>
          {err}
        </div>
      ))}
    </div>
  );
}

export const inputStyle: CSSProperties = {
  width: '100%',
  padding: '8px 10px',
  border: '1px solid #d1d5db',
  borderRadius: 6,
  fontSize: 14,
  boxSizing: 'border-box',
};
