import type { ChangeEvent, InputHTMLAttributes } from 'react';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'step' | 'min' | 'max'> & {
  /** Whole numbers only (counted items, sequences, days…). Defaults from a legacy `step` of 1. */
  integer?: boolean;
  /** Allow a leading minus sign. Defaults from a negative `min`. */
  allowNegative?: boolean;
  /** Legacy <input type="number"> props: only used to derive the defaults above. */
  step?: string | number;
  min?: string | number;
  max?: string | number;
};

/**
 * Application-wide numeric entry: a plain text input (no browser stepper/spinner) that only
 * accepts characters forming a number. Keystrokes that would make the value non-numeric are
 * ignored, so `onChange` always receives a number-shaped string (possibly empty or partial,
 * e.g. "12." while typing). Range and business rules stay with the form and the backend.
 */
export function NumericInput({ integer, allowNegative, step, min, max: _max, onChange, inputMode, ...rest }: Props) {
  const wholeOnly = integer ?? (step !== undefined && String(step) === '1');
  const negative = allowNegative ?? (min !== undefined && Number(min) < 0);
  const pattern = new RegExp(`^${negative ? '-?' : ''}\\d*${wholeOnly ? '' : '(\\.\\d*)?'}$`);

  function handleChange(e: ChangeEvent<HTMLInputElement>) {
    const value = e.target.value.replace(',', '.');
    if (!pattern.test(value)) return;
    if (value !== e.target.value) e.target.value = value;
    onChange?.(e);
  }

  return <input {...rest} type="text" inputMode={inputMode ?? (wholeOnly ? 'numeric' : 'decimal')} autoComplete="off" onChange={handleChange} />;
}
