export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return <div style={{ padding: 32, textAlign: 'center', color: '#6b7280' }}>{label}</div>;
}

export function EmptyState({ label = 'No records found.' }: { label?: string }) {
  return (
    <div style={{ padding: 40, textAlign: 'center', color: '#9ca3af', border: '1px dashed #e5e7eb', borderRadius: 8 }}>
      {label}
    </div>
  );
}

export function ErrorState({ message }: { message: string }) {
  return (
    <div
      style={{
        padding: 16,
        background: '#fef2f2',
        color: '#b91c1c',
        border: '1px solid #fecaca',
        borderRadius: 8,
        fontSize: 14,
      }}
    >
      {message}
    </div>
  );
}
