import type { ReactNode } from 'react';
import { inputStyle } from './FormField';

export function Toolbar({
  search,
  onSearchChange,
  children,
  actions,
}: {
  search?: string;
  onSearchChange?: (value: string) => void;
  children?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14, gap: 10, flexWrap: 'wrap' }}>
      <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        {onSearchChange && (
          <input
            placeholder="Search…"
            value={search ?? ''}
            onChange={(e) => onSearchChange(e.target.value)}
            style={{ ...inputStyle, width: 220 }}
          />
        )}
        {children}
      </div>
      <div>{actions}</div>
    </div>
  );
}
