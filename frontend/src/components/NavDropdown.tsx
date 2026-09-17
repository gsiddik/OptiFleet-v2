import { useEffect, useRef, useState } from 'react';
import { NavLink } from 'react-router-dom';

interface NavDropdownItem {
  to: string;
  label: string;
}

/**
 * Navbar menu group (Account / Organization / Access). Desktop/hover-capable
 * devices open it on hover with a short close delay so moving the pointer
 * from the trigger to the panel doesn't close it; touch devices open/close
 * it with a tap on the trigger (the same click handler works for both —
 * hover simply never fires on touch). Escape and an outside click/tap
 * always close it, and every item is a real focusable link so Tab/Enter
 * keyboard navigation works without any extra wiring.
 */
export function NavDropdown({ label, items }: { label: string; items: NavDropdownItem[] }) {
  const [open, setOpen] = useState(false);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  function openNow() {
    if (closeTimer.current) {
      clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
    setOpen(true);
  }

  function closeWithDelay() {
    closeTimer.current = setTimeout(() => setOpen(false), 150);
  }

  function closeNow() {
    if (closeTimer.current) {
      clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
    setOpen(false);
  }

  useEffect(() => {
    if (!open) return;
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') closeNow();
    }
    function onOutside(e: Event) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) closeNow();
    }
    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('mousedown', onOutside);
    document.addEventListener('touchstart', onOutside);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('mousedown', onOutside);
      document.removeEventListener('touchstart', onOutside);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => () => {
    if (closeTimer.current) clearTimeout(closeTimer.current);
  }, []);

  if (items.length === 0) return null;

  return (
    <div ref={containerRef} style={{ position: 'relative' }} onMouseEnter={openNow} onMouseLeave={closeWithDelay}>
      <button
        type="button"
        aria-haspopup="true"
        aria-expanded={open}
        onClick={() => (open ? closeNow() : openNow())}
        style={{
          background: 'none',
          border: 'none',
          fontSize: 14,
          color: '#374151',
          padding: '6px 8px',
          cursor: 'pointer',
          display: 'flex',
          alignItems: 'center',
          gap: 4,
          whiteSpace: 'nowrap',
        }}
      >
        {label}
        <span aria-hidden="true" style={{ fontSize: 9 }}>
          ▾
        </span>
      </button>
      {open && (
        <div
          role="menu"
          aria-label={label}
          style={{
            position: 'absolute',
            top: '100%',
            left: 0,
            background: '#fff',
            border: '1px solid #e5e7eb',
            borderRadius: 8,
            boxShadow: '0 4px 16px rgba(0,0,0,0.1)',
            minWidth: 190,
            zIndex: 60,
            padding: 4,
          }}
        >
          {items.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              role="menuitem"
              onClick={closeNow}
              style={({ isActive }) => ({
                display: 'block',
                padding: '8px 12px',
                fontSize: 13,
                borderRadius: 6,
                color: isActive ? '#1d4ed8' : '#374151',
                background: isActive ? '#eff6ff' : 'transparent',
                textDecoration: 'none',
              })}
            >
              {item.label}
            </NavLink>
          ))}
        </div>
      )}
    </div>
  );
}
