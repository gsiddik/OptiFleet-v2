import type { ReactNode } from 'react';

export function Modal({ open, title, onClose, children, width = 480 }: { open: boolean; title: string; onClose: () => void; children: ReactNode; width?: number }) {
  if (!open) return null;

  return (
    <div
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', overflowY: 'auto', padding: '5vh 16px', zIndex: 50 }}
      onClick={onClose}
    >
      <div
        style={{ background: '#fff', borderRadius: 10, padding: 24, width, maxWidth: '100%', boxShadow: '0 10px 30px rgba(0,0,0,0.2)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <h3 style={{ margin: 0, fontSize: 17 }}>{title}</h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', fontSize: 20, cursor: 'pointer', color: '#6b7280' }}>
            ×
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
