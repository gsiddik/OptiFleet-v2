export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export function Pagination({ meta, onPageChange }: { meta: PaginationMeta; onPageChange: (page: number) => void }) {
  if (meta.last_page <= 1) return null;

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 16, fontSize: 13, color: '#6b7280' }}>
      <button
        className="btn-secondary"
        disabled={meta.current_page <= 1}
        onClick={() => onPageChange(meta.current_page - 1)}
      >
        Previous
      </button>
      <span>
        Page {meta.current_page} of {meta.last_page} ({meta.total} total)
      </span>
      <button
        className="btn-secondary"
        disabled={meta.current_page >= meta.last_page}
        onClick={() => onPageChange(meta.current_page + 1)}
      >
        Next
      </button>
    </div>
  );
}
