import { cloneElement, isValidElement, type CSSProperties, type ReactNode } from 'react';

export function FormField({
  label,
  errors,
  required,
  children,
}: {
  label: string;
  errors?: string[];
  required?: boolean;
  children: ReactNode;
}) {
  const content =
    required && isValidElement<{ 'aria-required'?: boolean; required?: boolean }>(children)
      ? cloneElement(children, { 'aria-required': true, required: true })
      : children;

  return (
    <div style={{ marginBottom: 14 }}>
      <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 4, color: '#374151' }}>
        {label}
        {required && (
          <>
            <span aria-hidden="true" style={{ color: '#dc2626', marginLeft: 3 }}>
              *
            </span>
            <span className="sr-only"> (required)</span>
          </>
        )}
      </label>
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
