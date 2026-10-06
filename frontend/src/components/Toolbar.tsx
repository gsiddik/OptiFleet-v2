import type { ReactNode } from 'react';
import { inputStyle } from './FormField';
import { t } from '../i18n/i18n';

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
    <div className="toolbar" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14, gap: 10, flexWrap: 'wrap' }}>
      {/* Filters sit on the search's row (index.css .toolbar-filters) instead of stacking under it. */}
      <div className="toolbar-filters" style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap', flex: '1 1 auto', minWidth: 0 }}>
        {onSearchChange && (
          <input
            placeholder={t('common.search.search')}
            value={search ?? ''}
            onChange={(e) => onSearchChange(e.target.value)}
            aria-label={t('common.fields.search')}
            style={{ ...inputStyle, width: 220 }}
          />
        )}
        {children}
      </div>
      {actions && <div className="toolbar-actions" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>{actions}</div>}
    </div>
  );
}
