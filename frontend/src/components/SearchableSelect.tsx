import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { inputStyle } from './FormField';
import { t } from '../i18n/i18n';

export interface SearchableOption {
  value: string;
  label: string;
  /** Secondary identifier shown after the label (e.g. SKU). */
  hint?: string | null;
}

/**
 * Dropdown whose search box lives INSIDE the opened list (no separate search field). Options are
 * loaded through `loadOptions(search)` — server-side search with debounce — so it works for large
 * lists. The stored value is always the option's `value` (an id), never the display text.
 */
export function SearchableSelect({
  value,
  selectedLabel,
  onChange,
  loadOptions,
  placeholder = 'Select…',
  searchPlaceholder = 'Search…',
  ariaLabel,
  disabled = false,
  width = 280,
}: {
  value: string;
  selectedLabel?: string | null;
  onChange: (value: string, option: SearchableOption | null) => void;
  loadOptions: (search: string) => Promise<SearchableOption[]>;
  placeholder?: string;
  searchPlaceholder?: string;
  ariaLabel: string;
  disabled?: boolean;
  width?: number | string;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [options, setOptions] = useState<SearchableOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const loadRef = useRef(loadOptions);
  useEffect(() => {
    loadRef.current = loadOptions;
  }, [loadOptions]);

  // Debounced server-side search while the list is open.
  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    const handle = setTimeout(() => {
      setLoading(true);
      loadRef
        .current(search.trim())
        .then((rows) => {
          if (cancelled) return;
          setOptions(rows);
          setActive(0);
        })
        .catch(() => !cancelled && setOptions([]))
        .finally(() => !cancelled && setLoading(false));
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [open, search]);

  useEffect(() => {
    if (!open) return;
    searchRef.current?.focus();
    const onDocClick = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [open]);

  function choose(option: SearchableOption) {
    onChange(option.value, option);
    setOpen(false);
    setSearch('');
  }

  function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((i) => Math.min(i + 1, options.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (options[active]) choose(options[active]);
    } else if (e.key === 'Escape') {
      setOpen(false);
    }
  }

  const listId = `${ariaLabel.replace(/\s+/g, '-').toLowerCase()}-options`;

  return (
    <div ref={rootRef} style={{ position: 'relative', width, maxWidth: '100%' }}>
      <button
        type="button"
        role="combobox"
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        disabled={disabled}
        onClick={() => setOpen((o) => !o)}
        style={{ ...inputStyle, width: '100%', textAlign: 'left', display: 'flex', justifyContent: 'space-between', alignItems: 'center', cursor: disabled ? 'not-allowed' : 'pointer', background: '#fff' }}
      >
        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: value ? '#111827' : '#6b7280' }}>{value ? selectedLabel ?? value : placeholder}</span>
        <span aria-hidden="true" style={{ marginLeft: 8, color: '#6b7280' }}>
          ▾
        </span>
      </button>
      {open && (
        <div
          style={{ position: 'absolute', zIndex: 30, top: 'calc(100% + 4px)', left: 0, right: 0, minWidth: 240, background: '#fff', border: '1px solid #d1d5db', borderRadius: 6, boxShadow: '0 8px 20px rgba(0,0,0,0.12)' }}
        >
          <div style={{ padding: 6, borderBottom: '1px solid #e5e7eb' }}>
            <input
              ref={searchRef}
              aria-label={t('common.fields.ariaLabelSearch', { ariaLabel: ariaLabel })}
              placeholder={searchPlaceholder}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={onKeyDown}
              style={{ ...inputStyle, width: '100%' }}
            />
          </div>
          <ul id={listId} role="listbox" aria-label={ariaLabel} style={{ listStyle: 'none', margin: 0, padding: 4, maxHeight: 260, overflowY: 'auto' }}>
            {loading && <li style={{ padding: '6px 8px', fontSize: 13, color: '#6b7280' }}>{t('common.actions.loading')}</li>}
            {!loading && options.length === 0 && <li style={{ padding: '6px 8px', fontSize: 13, color: '#6b7280' }}>{t('common.empty.noMatches')}</li>}
            {!loading &&
              options.map((option, i) => (
                <li
                  key={option.value}
                  role="option"
                  aria-selected={option.value === value}
                  onMouseEnter={() => setActive(i)}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => choose(option)}
                  style={{ padding: '6px 8px', fontSize: 13, borderRadius: 4, cursor: 'pointer', background: i === active ? '#eff6ff' : option.value === value ? '#f3f4f6' : undefined }}
                >
                  {option.label}
                  {option.hint && <span style={{ color: '#6b7280' }}> — {option.hint}</span>}
                </li>
              ))}
          </ul>
        </div>
      )}
    </div>
  );
}
