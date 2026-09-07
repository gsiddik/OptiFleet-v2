import type { ReactNode } from 'react';

export interface Column<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  sortable?: boolean;
}

export function Table<T extends { id: string }>({
  columns,
  rows,
  sort,
  direction,
  onSort,
}: {
  columns: Column<T>[];
  rows: T[];
  sort?: string;
  direction?: 'asc' | 'desc';
  onSort?: (key: string) => void;
}) {
  return (
    <div style={{ overflowX: 'auto', border: '1px solid #e5e7eb', borderRadius: 8 }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
        <thead>
          <tr style={{ background: '#f9fafb', textAlign: 'left' }}>
            {columns.map((col) => (
              <th
                key={col.key}
                onClick={() => col.sortable && onSort?.(col.key)}
                style={{
                  padding: '10px 14px',
                  fontWeight: 600,
                  color: '#374151',
                  cursor: col.sortable ? 'pointer' : 'default',
                  borderBottom: '1px solid #e5e7eb',
                  whiteSpace: 'nowrap',
                }}
              >
                {col.header}
                {col.sortable && sort === col.key ? (direction === 'asc' ? ' ▲' : ' ▼') : ''}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
              {columns.map((col) => (
                <td key={col.key} style={{ padding: '10px 14px', verticalAlign: 'middle' }}>
                  {col.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
